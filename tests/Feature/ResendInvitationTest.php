<?php

namespace Tests\Feature;

use App\Mail\AccountInviteMail;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The way back for somebody whose invitation never arrived.
 *
 * An invitation link is signed for three days. Three of them once sat in a
 * queue nobody was draining, and by the time that was noticed the links inside
 * them had expired — leaving those people unable to set a password and HR with
 * no way to help beyond deleting the account and building it again.
 *
 * What matters here is not just that a message goes out. It is that pressing
 * this on a mixed selection does not do something surprising: nobody who
 * already has a password gets an email inviting them to set one, and a single
 * unreachable address does not take the rest of the batch down with it.
 */
class ResendInvitationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->admin = User::factory()->create(['is_super_admin' => true]);
        $this->admin->assignRole('Admin');
        Employee::factory()->create(['user_id' => $this->admin->id]);

        $this->actingAs($this->admin);

        Mail::fake();
    }

    /** A user who has been invited but has never set a password. */
    protected function pendingUser(array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['password_set_at' => null]);
        $user->assignRole('Employee');
        Employee::factory()->create(['user_id' => $user->id]);

        return $user->fresh();
    }

    #[Test]
    public function a_pending_invitation_can_be_sent_again(): void
    {
        $user = $this->pendingUser();

        Livewire::test('users.index')
            ->call('resendSelected', [$user->id])
            ->assertSet('statusMessage', '1 invitation sent again.');

        Mail::assertQueued(AccountInviteMail::class, fn ($mail) => $mail->hasTo($user->email));
    }

    #[Test]
    public function somebody_who_already_has_a_password_is_left_alone(): void
    {
        // The one that would embarrass HR: an email telling a person who has
        // been signing in for weeks to go and set their password.
        $settled = $this->pendingUser(['password_set_at' => now()]);

        Livewire::test('users.index')
            ->call('resendSelected', [$settled->id])
            ->assertSet('statusMessage', '1 skipped: that password is already set.');

        Mail::assertNothingQueued();
    }

    #[Test]
    public function a_disabled_account_is_not_invited_back_in(): void
    {
        $disabled = $this->pendingUser(['is_active' => false]);

        Livewire::test('users.index')
            ->call('resendSelected', [$disabled->id])
            ->assertSet('statusMessage', '1 skipped: the account is disabled or has no employee linked.');

        Mail::assertNothingQueued();
    }

    #[Test]
    public function a_mixed_selection_reports_each_group_separately(): void
    {
        $pending = $this->pendingUser();
        $settled = $this->pendingUser(['password_set_at' => now()]);
        $disabled = $this->pendingUser(['is_active' => false]);

        Livewire::test('users.index')
            ->call('resendSelected', [$pending->id, $settled->id, $disabled->id])
            ->assertSet('statusMessage', '1 invitation sent again. 1 skipped: that password is already set.'
                . ' 1 skipped: the account is disabled or has no employee linked.');

        Mail::assertQueuedCount(1);
    }

    #[Test]
    public function every_pending_invitation_in_the_selection_is_sent(): void
    {
        $users = collect(range(1, 3))->map(fn () => $this->pendingUser());

        Livewire::test('users.index')
            ->call('resendSelected', $users->pluck('id')->all())
            ->assertSet('statusMessage', '3 invitations sent again.');

        Mail::assertQueuedCount(3);
    }

    #[Test]
    public function the_link_that_goes_out_is_freshly_signed(): void
    {
        // The whole point. Sending the original link again would post somebody
        // a URL that expired days ago.
        $user = $this->pendingUser();

        Livewire::test('users.index')->call('resendSelected', [$user->id]);

        Mail::assertQueued(AccountInviteMail::class, function ($mail) {
            $this->assertMatchesRegularExpression('/set-password/', $mail->url);
            $this->assertMatchesRegularExpression('/expires=\d+/', $mail->url);

            $expiry = (int) preg_replace('/.*expires=(\d+).*/', '$1', $mail->url);
            $this->assertGreaterThan(now()->addDays(2)->timestamp, $expiry);

            return true;
        });
    }
}
