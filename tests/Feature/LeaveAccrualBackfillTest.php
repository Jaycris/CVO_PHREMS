<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveCreditTransaction;
use App\Models\LeaveType;
use App\Services\LeaveService;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Leave that people had earned but were never given.
 *
 * Accrual only ever ran forward, from the day the scheduler first worked, and
 * looked at nobody's hire date. Two things followed. Somebody hired in May had
 * nothing to show for May, June or July, because no accrual had run yet. And
 * somebody hired on the 9th collected a full month's credit on the 10th, for a
 * month they had worked one day of.
 *
 * Vacation leave converts to cash at the end of the year, so every credit here
 * is eventually money.
 */
class LeaveAccrualBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected LeaveService $leave;

    protected LeaveType $vacation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(LeaveTypeSeeder::class);

        $this->leave = new LeaveService;
        $this->vacation = LeaveType::where('code', 'VL')->sole();
    }

    protected function hiredOn(string $date, array $attributes = []): Employee
    {
        return Employee::factory()->create($attributes + ['hire_date' => $date]);
    }

    protected function vacationDays(Employee $employee): float
    {
        return (float) LeaveCreditTransaction::where('employee_id', $employee->id)
            ->where('leave_type_id', $this->vacation->id)
            ->sum('amount');
    }

    #[Test]
    public function somebody_hired_in_may_has_the_months_since_then(): void
    {
        // The user's own example. Hired after the 10th of May, so May itself
        // does not count: June, July and August do.
        $employee = $this->hiredOn('2026-05-14');

        $this->leave->backfillAccruals(Carbon::parse('2026-08-27'));

        $this->assertEqualsWithDelta(2.499, $this->vacationDays($employee), 0.001);
    }

    #[Test]
    public function somebody_hired_before_the_accrual_day_gets_that_month_too(): void
    {
        // Hired on the 3rd, so the 10th of May had already come round while
        // they were employed.
        $employee = $this->hiredOn('2026-05-03');

        $this->leave->backfillAccruals(Carbon::parse('2026-08-27'));

        $this->assertEqualsWithDelta(3.332, $this->vacationDays($employee), 0.001);
    }

    #[Test]
    public function nothing_accrues_for_months_before_somebody_joined(): void
    {
        $employee = $this->hiredOn('2026-08-01');

        $this->leave->backfillAccruals(Carbon::parse('2026-08-27'));

        // August only.
        $this->assertEqualsWithDelta(0.833, $this->vacationDays($employee), 0.001);
    }

    #[Test]
    public function being_hired_the_day_before_the_accrual_does_not_earn_a_whole_month(): void
    {
        // It used to. Nothing looked at the hire date at all.
        $employee = $this->hiredOn('2026-08-11');

        $this->leave->backfillAccruals(Carbon::parse('2026-08-27'));

        $this->assertSame(0.0, $this->vacationDays($employee));
    }

    #[Test]
    public function running_it_twice_grants_nothing_the_second_time(): void
    {
        // The guard that makes this safe to run again, and safe to run
        // alongside the monthly accrual that is already scheduled.
        $employee = $this->hiredOn('2026-05-14');

        $first = $this->leave->backfillAccruals(Carbon::parse('2026-08-27'));
        $second = $this->leave->backfillAccruals(Carbon::parse('2026-08-27'));

        $this->assertSame(3, $first);
        $this->assertSame(0, $second);
        $this->assertEqualsWithDelta(2.499, $this->vacationDays($employee), 0.001);
    }

    #[Test]
    public function the_monthly_run_no_longer_double_credits_when_run_twice(): void
    {
        $employee = $this->hiredOn('2026-01-05');

        $this->leave->runMonthlyAccrual(Carbon::parse('2026-08-10'));
        $this->leave->runMonthlyAccrual(Carbon::parse('2026-08-10'));

        $this->assertEqualsWithDelta(0.833, $this->vacationDays($employee), 0.001);
    }

    #[Test]
    public function somebody_who_has_left_stops_accruing(): void
    {
        // Leave was still building up for former staff, and vacation leave is
        // cash-convertible, so it was quietly still costing money.
        $employee = $this->hiredOn('2026-01-05', ['separation_date' => '2026-06-30']);

        $this->leave->backfillAccruals(Carbon::parse('2026-08-27'));

        // January through June: six accrual days, nothing after.
        $this->assertEqualsWithDelta(4.998, $this->vacationDays($employee), 0.001);
    }

    #[Test]
    public function a_probationary_employee_still_accrues(): void
    {
        // They cannot spend it, but it is waiting for them when they
        // regularize. This is what the screen wrongly says does not happen.
        $employee = $this->hiredOn('2026-05-14', ['employment_status' => 'Probationary']);

        $this->leave->backfillAccruals(Carbon::parse('2026-08-27'));

        $this->assertEqualsWithDelta(2.499, $this->vacationDays($employee), 0.001);
    }

    #[Test]
    public function the_command_reports_what_it_granted(): void
    {
        $this->hiredOn('2026-05-14');

        $this->travelTo(Carbon::parse('2026-08-27'));

        $this->artisan('leave:backfill-accrual')
            ->expectsOutputToContain('Granted 3 missed leave credit(s).')
            ->assertSuccessful();
    }

    #[Test]
    public function a_dry_run_writes_nothing(): void
    {
        $employee = $this->hiredOn('2026-05-14');

        $this->travelTo(Carbon::parse('2026-08-27'));

        $this->artisan('leave:backfill-accrual --dry-run')
            ->expectsOutputToContain('3 credit(s) would be granted')
            ->assertSuccessful();

        $this->assertSame(0.0, $this->vacationDays($employee));
    }
}
