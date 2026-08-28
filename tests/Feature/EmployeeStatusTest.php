<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Whether somebody still works here, which nothing on screen used to say.
 *
 * The directory showed employment status — Regular, Probationary — and that is
 * a different fact. Somebody who resigned keeps their Regular status on the way
 * out, so the list carried a green "Regular" badge for people who had left
 * months earlier, and the only trace of their leaving was a date buried in the
 * payroll settings modal.
 */
class EmployeeStatusTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->admin = User::factory()->create(['is_super_admin' => true]);
        $this->admin->assignRole('Admin');

        $this->employee = Employee::factory()->create([
            'hire_date' => '2026-01-05',
            'employment_status' => 'Regular',
        ]);

        $this->actingAs($this->admin);
    }

    #[Test]
    public function somebody_with_no_last_day_is_active(): void
    {
        $this->assertSame('Active', $this->employee->statusLabel());
        $this->assertSame('green', $this->employee->statusColor());
    }

    #[Test]
    public function the_status_says_how_they_left(): void
    {
        $this->employee->update([
            'separation_date' => '2026-06-30',
            'separation_type' => 'resigned',
        ]);

        $this->assertSame('Resigned', $this->employee->fresh()->statusLabel());
    }

    #[Test]
    public function a_termination_is_marked_differently_from_a_resignation(): void
    {
        $this->employee->update([
            'separation_date' => '2026-06-30',
            'separation_type' => 'terminated',
        ]);

        $this->assertSame('Terminated', $this->employee->fresh()->statusLabel());
        $this->assertSame('red', $this->employee->fresh()->statusColor());
    }

    #[Test]
    public function a_last_day_still_to_come_reads_as_leaving(): void
    {
        // Filing a resignation in advance must not cut somebody off on the day
        // they hand in their notice.
        $this->employee->update([
            'separation_date' => now()->addMonth()->toDateString(),
            'separation_type' => 'resigned',
        ]);

        $employee = $this->employee->fresh();

        $this->assertFalse($employee->isSeparated());
        $this->assertSame('Leaving', $employee->statusLabel());
    }

    #[Test]
    public function hr_can_record_how_somebody_left(): void
    {
        Livewire::test('employees.show', ['employee' => $this->employee])
            ->set('separationDate', '2026-06-30')
            ->set('separationType', 'resigned')
            ->set('separationReason', 'Moved to Cebu')
            ->call('savePayrollSettings')
            ->assertHasNoErrors();

        $employee = $this->employee->fresh();

        $this->assertSame('2026-06-30', $employee->separation_date->toDateString());
        $this->assertSame('resigned', $employee->separation_type);
        $this->assertSame('Moved to Cebu', $employee->separation_reason);
    }

    #[Test]
    public function a_last_day_without_saying_how_is_refused(): void
    {
        // "They left" is not an answer anybody can use six months later.
        Livewire::test('employees.show', ['employee' => $this->employee])
            ->set('separationDate', '2026-06-30')
            ->set('separationType', '')
            ->call('savePayrollSettings')
            ->assertHasErrors('separationType');

        $this->assertNull($this->employee->fresh()->separation_date);
    }

    #[Test]
    public function putting_somebody_back_on_the_roster_clears_how_they_left(): void
    {
        $this->employee->update([
            'separation_date' => '2026-06-30',
            'separation_type' => 'resigned',
        ]);

        Livewire::test('employees.show', ['employee' => $this->employee])
            ->set('separationDate', '')
            ->call('savePayrollSettings')
            ->assertHasNoErrors();

        $employee = $this->employee->fresh();

        $this->assertNull($employee->separation_date);
        $this->assertNull($employee->separation_type, 'A cleared separation left its type behind.');
        $this->assertSame('Active', $employee->statusLabel());
    }

    #[Test]
    public function the_directory_shows_the_status(): void
    {
        $this->employee->update([
            'separation_date' => '2026-06-30',
            'separation_type' => 'terminated',
        ]);

        $this->get('/employees')->assertOk()->assertSee('Terminated');
    }

    #[Test]
    public function the_profile_shows_the_status(): void
    {
        $this->employee->update([
            'separation_date' => '2026-06-30',
            'separation_type' => 'resigned',
        ]);

        $this->get('/employees/' . $this->employee->id)->assertOk()->assertSee('Resigned');
    }
}
