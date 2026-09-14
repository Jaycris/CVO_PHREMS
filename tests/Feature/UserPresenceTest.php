<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Whether somebody is in PHREMS right now.
 *
 * Stamped on request rather than pushed over a socket, because the app runs on
 * shared hosting with no websocket to push over. Accurate to the minute, which
 * is finer than the five-minute window that decides the answer.
 *
 * It says nothing about work. Somebody on a call for four hours touches PHREMS
 * once all morning, and a booth team at an exhibit never opens it — attendance
 * is what says whether they worked.
 */
class UserPresenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    #[Test]
    public function using_the_app_marks_somebody_as_here(): void
    {
        $user = User::factory()->create(['last_seen_at' => null]);
        $user->assignRole('Employee');

        $this->actingAs($user)->get('/dashboard')->assertOk();

        $this->assertNotNull($user->fresh()->last_seen_at);
        $this->assertTrue($user->fresh()->isOnline());
    }

    #[Test]
    public function it_runs_where_the_session_actually_exists(): void
    {
        /*
         * This is the one that would have caught the bug the test above did
         * not. Appended to the global stack the middleware runs before
         * StartSession, so $request->user() is always null and nothing is ever
         * recorded — and actingAs() hides that completely, because it sets the
         * guard directly instead of going through a session.
         *
         * Asserting the registration rather than the behaviour, since the
         * behaviour is indistinguishable in a test that fakes the login.
         */
        $kernel = app(\Illuminate\Foundation\Http\Kernel::class);

        $global = (fn () => $this->middleware)->call($kernel);
        $web = (fn () => $this->middlewareGroups['web'])->call($kernel);

        $this->assertContains(\App\Http\Middleware\RecordLastSeen::class, $web);
        $this->assertNotContains(\App\Http\Middleware\RecordLastSeen::class, $global);
    }

    #[Test]
    public function somebody_who_stopped_a_while_ago_is_not_online(): void
    {
        $user = User::factory()->create(['last_seen_at' => now()->subMinutes(30)]);

        $this->assertFalse($user->isOnline());
        $this->assertStringContainsString('Last seen', $user->presenceLabel());
    }

    #[Test]
    public function a_page_left_open_for_a_few_minutes_still_counts_as_here(): void
    {
        // Any shorter a window and somebody reading a payslip flickers offline
        // mid-sentence.
        $user = User::factory()->create(['last_seen_at' => now()->subMinutes(3)]);

        $this->assertTrue($user->isOnline());
        $this->assertSame('Online', $user->presenceLabel());
        $this->assertSame('green', $user->presenceColor());
    }

    #[Test]
    public function an_account_nobody_has_used_says_so(): void
    {
        $user = User::factory()->create(['last_seen_at' => null]);

        $this->assertFalse($user->isOnline());
        $this->assertSame('Never signed in', $user->presenceLabel());
    }

    #[Test]
    public function a_disabled_account_is_never_shown_as_online(): void
    {
        /*
         * It cannot make a request, so a green dot beside somebody whose access
         * was revoked this morning would be worse than showing nothing.
         */
        $user = User::factory()->create([
            'is_active' => false,
            'last_seen_at' => now(),
        ]);

        $this->assertFalse($user->isOnline());
        $this->assertSame('Disabled', $user->presenceLabel());
        $this->assertSame('red', $user->presenceColor());
    }

    #[Test]
    public function the_stamp_is_not_rewritten_on_every_request(): void
    {
        /*
         * Otherwise every page load, every Livewire poll and every keystroke in
         * a live-bound field is a write — for a figure nobody reads to the
         * second.
         */
        $user = User::factory()->create(['last_seen_at' => now()->subSeconds(5)]);
        $user->assignRole('Employee');

        $before = $user->fresh()->last_seen_at;

        $this->actingAs($user)->get('/dashboard')->assertOk();

        $this->assertTrue($before->equalTo($user->fresh()->last_seen_at));
    }

    #[Test]
    public function a_stale_stamp_is_refreshed(): void
    {
        $user = User::factory()->create(['last_seen_at' => now()->subMinutes(10)]);
        $user->assignRole('Employee');

        $before = $user->fresh()->last_seen_at;

        $this->actingAs($user)->get('/dashboard')->assertOk();

        $this->assertTrue($user->fresh()->last_seen_at->gt($before));
    }

    #[Test]
    public function being_seen_does_not_count_as_the_account_being_edited(): void
    {
        // "Last changed" is a different question from "last seen", and a page
        // load is not an edit to somebody's account.
        $user = User::factory()->create(['last_seen_at' => now()->subHour()]);
        $user->assignRole('Employee');

        $updatedAt = $user->fresh()->updated_at;

        $this->actingAs($user)->get('/dashboard')->assertOk();

        $this->assertTrue($updatedAt->equalTo($user->fresh()->updated_at));
    }

    #[Test]
    public function a_signed_out_visitor_stamps_nothing(): void
    {
        $this->get('/login')->assertOk();

        $this->assertSame(0, User::whereNotNull('last_seen_at')->count());
    }

    #[Test]
    public function the_users_list_shows_the_dot(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $admin->assignRole('Admin');

        User::factory()->create(['last_seen_at' => now()])->assignRole('Employee');

        $this->actingAs($admin)
            ->get('/users')
            ->assertOk()
            ->assertSee('Presence')
            ->assertSee('Online');
    }

    #[Test]
    public function the_dots_refresh_themselves(): void
    {
        /*
         * Polling rather than a websocket: the app runs on shared hosting with
         * nothing that can hold a connection open.
         *
         * .visible is the half that matters for cost — without it a directory
         * left open overnight is a request every thirty seconds until morning,
         * and it would also keep stamping the viewer as online while nobody was
         * looking at the screen.
         */
        $admin = User::factory()->create(['is_super_admin' => true]);
        $admin->assignRole('Admin');

        $this->actingAs($admin)
            ->get('/users')
            ->assertOk()
            ->assertSee('wire:poll.30s.visible', false);
    }

    #[Test]
    public function the_users_list_can_be_filtered_to_people_online_now(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $admin->assignRole('Admin');
        $online = User::factory()->create(['name' => 'Online Person', 'last_seen_at' => now()]);
        $online->assignRole('Employee');
        $offline = User::factory()->create(['name' => 'Offline Person', 'last_seen_at' => now()->subMinutes(30)]);
        $offline->assignRole('Employee');

        $this->actingAs($admin);

        Livewire::test('users.index')
            ->set('presence', 'online')
            ->assertSee('Online Person')
            ->assertDontSee('Offline Person');
    }

    #[Test]
    public function the_offline_filter_includes_stale_disabled_and_never_signed_in_accounts(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $admin->assignRole('Admin');
        $online = User::factory()->create(['name' => 'Online Person', 'last_seen_at' => now()]);
        $online->assignRole('Employee');

        foreach ([
            ['name' => 'Stale Person', 'last_seen_at' => now()->subMinutes(30), 'is_active' => true],
            ['name' => 'Never Signed In Person', 'last_seen_at' => null, 'is_active' => true],
            ['name' => 'Disabled Person', 'last_seen_at' => now(), 'is_active' => false],
        ] as $attributes) {
            User::factory()->create($attributes)->assignRole('Employee');
        }

        $this->actingAs($admin);

        Livewire::test('users.index')
            ->set('presence', 'offline')
            ->assertSee('Stale Person')
            ->assertSee('Never Signed In Person')
            ->assertSee('Disabled Person')
            ->assertDontSee('Online Person');
    }
}
