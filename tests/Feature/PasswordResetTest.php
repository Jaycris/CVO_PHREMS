<?php

namespace Tests\Feature;

use App\Mail\PasswordResetMail;
use App\Models\Employee;
use App\Models\User;
use App\Services\Auth\PasswordResetLink;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Getting somebody back into an account they are locked out of.
 *
 * There was no way. Resend Invitation refuses anybody who already has a
 * password, and the login page has no "forgot password" — so the only route
 * back was deleting the account and rebuilding it, which hands somebody a new
 * user code for forgetting a password.
 *
 * HR starts the reset and the employee finishes it. Nobody at the company ever
 * sees or sets the password, which is what keeps an account provably theirs
 * when the same login approves payroll.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->hr = User::factory()->create(['name' => 'Gibb Ledesma']);
        $this->hr->assignRole('Admin');
        $this->hr->givePermissionTo('users.manage');
    }

    /** Somebody who has been using their account and is now locked out. */
    protected function lockedOutUser(): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('the-old-one'),
            'password_set_at' => now()->subMonths(2),
            'is_active' => true,
        ]);

        $employee = Employee::factory()->create(['first_name' => 'Maria']);
        $employee->forceFill(['user_id' => $user->id])->save();

        return $user->fresh();
    }

    #[Test]
    public function hr_can_send_a_reset_link(): void
    {
        Mail::fake();

        $user = $this->lockedOutUser();

        Livewire::actingAs($this->hr)
            ->test('users.index')
            ->call('sendResetSelected', [$user->id])
            ->assertSee('1 reset link sent');

        Mail::assertQueued(PasswordResetMail::class, function (PasswordResetMail $mail) use ($user) {
            // Named, so an employee receiving a link they did not ask for knows
            // who to ring about it.
            return $mail->startedBy === 'Gibb Ledesma'
                && $mail->hasTo($user->email);
        });
    }

    #[Test]
    public function hr_never_sets_or_sees_the_password(): void
    {
        // The whole point of a link rather than a typed temporary password.
        Mail::fake();

        $user = $this->lockedOutUser();
        $before = $user->password;

        Livewire::actingAs($this->hr)
            ->test('users.index')
            ->call('sendResetSelected', [$user->id]);

        $this->assertSame($before, $user->fresh()->password, 'Sending a link must not change the password.');
        $this->assertTrue(Hash::check('the-old-one', $user->fresh()->password), 'The old password keeps working until they choose a new one.');
    }

    /** The fp the real link carries, so tests drive the genuine guard. */
    protected function fingerprintFor(User $user): string
    {
        parse_str(parse_url(PasswordResetLink::for($user), PHP_URL_QUERY) ?? '', $query);

        return $query['fp'] ?? '';
    }

    #[Test]
    public function the_employee_can_choose_a_new_password(): void
    {
        $user = $this->lockedOutUser();

        $this->get(PasswordResetLink::for($user))->assertOk();

        Livewire::withQueryParams(['fp' => $this->fingerprintFor($user)])
            ->test('public.reset-password', ['user' => $user])
            ->set('password', 'a-brand-new-one')
            ->set('password_confirmation', 'a-brand-new-one')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('a-brand-new-one', $user->fresh()->password));
    }

    #[Test]
    public function the_signature_is_enforced_by_the_route(): void
    {
        /*
         * Not repeated inside the component, so this is what proves it is
         * actually there — an unsigned request must never reach mount.
         */
        $user = $this->lockedOutUser();

        $this->get(route('password.reset', ['user' => $user->id, 'fp' => $this->fingerprintFor($user)]))
            ->assertForbidden();
    }

    #[Test]
    public function a_link_stops_working_once_it_has_been_used(): void
    {
        /*
         * A signature alone only says the app issued this and it has not
         * expired — the same link would keep working until it did. This link
         * carries a fingerprint of the password it was issued against, so
         * setting a new one retires it.
         *
         * It matters because the link sits in a mailbox: a forwarded email or a
         * shared laptop is otherwise a way back into the account weeks later.
         */
        $user = $this->lockedOutUser();
        $link = PasswordResetLink::for($user);

        $this->get($link)->assertOk();

        $user->update([
            'password' => Hash::make('a-brand-new-one'),
            'password_set_at' => now(),
        ]);

        $this->get($link)->assertForbidden();
    }

    #[Test]
    public function an_older_link_dies_when_a_newer_one_is_issued(): void
    {
        // Two resets in a row: only the latest email should work.
        $user = $this->lockedOutUser();

        $first = PasswordResetLink::for($user);

        $user->update(['password_set_at' => now()->addSecond()]);

        $second = PasswordResetLink::for($user->fresh());

        $this->get($first)->assertForbidden();
        $this->get($second)->assertOk();
    }

    #[Test]
    public function a_link_without_a_valid_signature_is_refused(): void
    {
        $user = $this->lockedOutUser();

        $this->get(route('password.reset', ['user' => $user->id, 'fp' => 'made-up']))->assertForbidden();
    }

    #[Test]
    public function a_disabled_account_cannot_be_reset_into(): void
    {
        // A new password would not let them sign in anyway.
        $user = $this->lockedOutUser();
        $link = PasswordResetLink::for($user);

        $user->update(['is_active' => false]);

        $this->get($link)->assertForbidden();
    }

    #[Test]
    public function hr_is_not_offered_a_reset_for_an_account_with_no_password_yet(): void
    {
        // Those need the invitation, which says "activate your account".
        Mail::fake();

        $user = User::factory()->create(['password_set_at' => null]);
        Employee::factory()->create()->forceFill(['user_id' => $user->id])->save();

        Livewire::actingAs($this->hr)
            ->test('users.index')
            ->call('sendResetSelected', [$user->id])
            ->assertSee('use Resend Invitation instead');

        Mail::assertNothingQueued();
    }

    #[Test]
    public function a_disabled_account_is_skipped_rather_than_emailed(): void
    {
        Mail::fake();

        $user = $this->lockedOutUser();
        $user->update(['is_active' => false]);

        Livewire::actingAs($this->hr)
            ->test('users.index')
            ->call('sendResetSelected', [$user->id])
            ->assertSee('the account is disabled');

        Mail::assertNothingQueued();
    }

    #[Test]
    public function somebody_without_users_manage_cannot_reach_the_screen(): void
    {
        $employee = User::factory()->create();
        $employee->assignRole('Employee');

        $this->actingAs($employee)->get('/users')->assertForbidden();
    }

    #[Test]
    public function the_new_password_has_to_be_confirmed_and_long_enough(): void
    {
        $user = $this->lockedOutUser();

        $fp = $this->fingerprintFor($user);

        Livewire::withQueryParams(['fp' => $fp])
            ->test('public.reset-password', ['user' => $user])
            ->set('password', 'short')
            ->set('password_confirmation', 'short')
            ->call('submit')
            ->assertHasErrors('password');

        Livewire::withQueryParams(['fp' => $fp])
            ->test('public.reset-password', ['user' => $user])
            ->set('password', 'a-long-enough-one')
            ->set('password_confirmation', 'something-else')
            ->call('submit')
            ->assertHasErrors('password');

        $this->assertTrue(Hash::check('the-old-one', $user->fresh()->password));
    }
}
