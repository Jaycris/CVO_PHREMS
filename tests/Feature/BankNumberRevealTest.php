<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reading a payout account off the profile when it is time to pay.
 *
 * The number was masked everywhere outside the approval panel, which is right
 * for a page left open all day but useless to whoever actually sends the
 * money. It reveals on a click instead, and only for the people whose job
 * touches it.
 */
class BankNumberRevealTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->employee = Employee::factory()->create([
            'bank_name' => 'Metrobank',
            'bank_account_name' => 'George Supangan',
            'bank_account_number' => '3333333333',
        ]);
    }

    /** Somebody holding a permission through their position, as in real use. */
    protected function userWith(?string $permission, string $title): User
    {
        $position = Position::factory()->create(['title' => $title]);

        if ($permission) {
            $position->givePermissionTo($permission);
        }

        $employee = Employee::factory()->create(['position_id' => $position->id]);
        $user = User::factory()->create();
        $employee->forceFill(['user_id' => $user->id])->save();
        $user->assignRole('Admin');

        return $user->fresh();
    }

    #[Test]
    public function accounting_can_reveal_the_number_to_pay_them(): void
    {
        // The people who are told about changes are the people who send the
        // money, so they are the ones who need to read it.
        $this->actingAs($this->userWith('bank_details.notify', 'Accounting'));

        Livewire::test('employees.show', ['employee' => $this->employee])
            ->assertDontSee('3333333333')
            ->assertSee('Show number')
            ->call('toggleBankNumber')
            ->assertSee('3333333333')
            ->call('toggleBankNumber')
            ->assertDontSee('3333333333');
    }

    #[Test]
    public function the_ceo_who_approves_changes_can_reveal_it_too(): void
    {
        $this->actingAs($this->userWith('bank_details.approve', 'Chief Executive Officer'));

        Livewire::test('employees.show', ['employee' => $this->employee])
            ->call('toggleBankNumber')
            ->assertSee('3333333333');
    }

    #[Test]
    public function everybody_else_is_refused_and_never_offered_it(): void
    {
        $this->actingAs($this->userWith(null, 'Branding Specialist'));

        Livewire::test('employees.show', ['employee' => $this->employee])
            ->assertDontSee('Show number')
            ->call('toggleBankNumber')
            ->assertStatus(403);
    }

    #[Test]
    public function an_employee_with_no_account_on_file_is_not_offered_it(): void
    {
        $this->actingAs($this->userWith('bank_details.notify', 'Accounting'));

        $noAccount = Employee::factory()->create([
            'bank_name' => null,
            'bank_account_name' => null,
            'bank_account_number' => null,
        ]);

        Livewire::test('employees.show', ['employee' => $noAccount])
            ->assertSee('Not provided')
            ->assertDontSee('Show number');
    }
}
