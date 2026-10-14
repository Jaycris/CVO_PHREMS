<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Catching up the logins that drifted before anything kept them in step.
 *
 * The observer only fires when a company email changes, which never happens
 * for a profile that is already correct and a login that is not. Those need
 * a pass of their own.
 */
class AlignLoginEmailsTest extends TestCase
{
    use RefreshDatabase;

    protected function pair(string $login, string $company): Employee
    {
        $user = User::factory()->create(['email' => $login]);

        return Employee::factory()->create([
            'company_email' => $company,
            'user_id' => $user->id,
        ]);
    }

    #[Test]
    public function it_lists_what_would_move_and_changes_nothing(): void
    {
        $employee = $this->pair('old@inkspiremediallc.com', 'new@inkspiremediahouse.com');

        $this->artisan('phrems:align-login-emails')
            ->expectsOutputToContain('1 login(s) would move')
            ->assertSuccessful();

        $this->assertSame('old@inkspiremediallc.com', $employee->user->fresh()->email);
    }

    #[Test]
    public function apply_moves_them(): void
    {
        $employee = $this->pair('old@inkspiremediallc.com', 'new@inkspiremediahouse.com');

        $this->artisan('phrems:align-login-emails --apply')
            ->expectsOutputToContain('1 login(s) moved')
            ->assertSuccessful();

        $this->assertSame('new@inkspiremediahouse.com', $employee->user->fresh()->email);
    }

    #[Test]
    public function a_login_already_matching_is_left_out_of_it(): void
    {
        $this->pair('same@inkspiremediahouse.com', 'same@inkspiremediahouse.com');

        $this->artisan('phrems:align-login-emails --apply')
            ->expectsOutputToContain('Every login already matches')
            ->assertSuccessful();
    }

    #[Test]
    public function an_address_another_account_already_uses_is_skipped(): void
    {
        User::factory()->create(['email' => 'taken@inkspiremediahouse.com']);
        $employee = $this->pair('old@inkspiremediallc.com', 'taken@inkspiremediahouse.com');

        $this->artisan('phrems:align-login-emails --apply')
            ->expectsOutputToContain('1 skipped')
            ->assertSuccessful();

        // Two people cannot share one login. Sorting out which of them owns
        // the address is a decision for a person, not for this command.
        $this->assertSame('old@inkspiremediallc.com', $employee->user->fresh()->email);
    }
}
