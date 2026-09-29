<?php

namespace Tests\Feature;

use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\LeaveCreditTransaction;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\LeaveService;
use App\Services\Payroll\AttendanceAggregator;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Payroll\PayrollTestCase;

/**
 * Half a day off: a morning at the clinic, an afternoon at the embassy.
 *
 * The leave half of it is easy. The payroll half is where it earns its keep —
 * somebody on a half day arrives late or leaves early by arrangement, and
 * charging them for that would take the time twice.
 */
class HalfDayLeaveTest extends PayrollTestCase
{
    protected LeaveService $leave;

    protected LeaveType $vacation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LeaveTypeSeeder::class);

        $this->leave = new LeaveService;
        $this->vacation = LeaveType::where('code', 'VL')->sole();
    }

    protected function regularEmployee(string $schedule = 'day'): Employee
    {
        $employee = $this->makeEmployee(20000, $schedule, [
            'employment_status' => 'Regular',
            'hire_date' => '2024-01-05',
        ]);

        $employee->forceFill(['user_id' => User::factory()->create()->id])->save();

        LeaveCreditTransaction::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $this->vacation->id,
            'transaction_date' => '2026-01-01',
            'amount' => 10,
            'reason' => 'opening_balance',
        ]);

        return $employee->fresh();
    }

    #[Test]
    public function half_a_day_costs_half_a_credit(): void
    {
        $employee = $this->regularEmployee();

        $request = $this->leave->submit($employee, $this->vacation, '2026-08-12', '2026-08-12', null, LeaveRequest::MORNING);

        $this->assertSame(0.5, (float) $request->days_requested);
        $this->assertTrue($request->isHalfDay());
        $this->assertSame('Half day (Morning)', $request->daysLabel());
        $this->assertFalse($request->is_lwop, 'ten credits covers half a day');
    }

    #[Test]
    public function a_half_day_covers_one_date(): void
    {
        $employee = $this->regularEmployee();

        $this->expectExceptionMessage('A half day covers one date');

        $this->leave->submit($employee, $this->vacation, '2026-08-12', '2026-08-14', null, LeaveRequest::AFTERNOON);
    }

    #[Test]
    public function approving_it_takes_half_a_credit_and_no_more(): void
    {
        $employee = $this->regularEmployee();
        $ceo = $this->makeEmployee(60000);

        $request = $this->leave->submit($employee, $this->vacation, '2026-08-12', '2026-08-12', null, LeaveRequest::MORNING);
        $this->leave->ceoDecide($request, $ceo, approved: true);

        $this->assertEqualsWithDelta(9.5, $employee->fresh()->leaveBalance($this->vacation), 0.001);
    }

    /** @return array<string, mixed> */
    protected function counters(Employee $employee, array $period): array
    {
        return (new AttendanceAggregator)
            ->aggregate(collect([$employee]), $period['start'], $period['end'])[$employee->id];
    }

    #[Test]
    public function the_day_still_counts_as_worked_and_costs_no_absence(): void
    {
        $period = $this->period();               // Aug 11-25 2026
        $employee = $this->regularEmployee();
        $this->fillAttendance($employee, $period);

        $request = $this->leave->submit($employee, $this->vacation, '2026-08-12', '2026-08-12', null, LeaveRequest::MORNING);
        $request->update(['status' => 'approved']);

        $counters = $this->counters($employee, $period);

        $this->assertSame(0.0, (float) $counters['days_absent']);
        $this->assertSame(0.5, (float) $counters['days_on_paid_leave']);
        $this->assertGreaterThan(0, $counters['days_present']);
    }

    #[Test]
    public function arriving_at_midday_on_a_half_day_is_not_lateness(): void
    {
        // The whole point: they were excused, so the hours they missed must not
        // be charged as lateness on top of the credit they spent.
        $period = $this->period();
        $employee = $this->regularEmployee();
        $this->fillAttendance($employee, $period);

        $day = AttendanceDay::where('employee_id', $employee->id)->whereDate('work_date', '2026-08-12')->sole();
        $day->update(['time_in' => Carbon::parse('2026-08-12 13:00:00')]);

        $request = $this->leave->submit($employee, $this->vacation, '2026-08-12', '2026-08-12', null, LeaveRequest::MORNING);
        $request->update(['status' => 'approved']);

        $this->assertSame(0, $this->counters($employee, $period)['late_minutes']);
    }

    #[Test]
    public function half_a_day_of_leave_without_pay_deducts_half_a_day(): void
    {
        $period = $this->period();
        $employee = $this->regularEmployee();
        $this->fillAttendance($employee, $period);

        $request = $this->leave->submit($employee, $this->vacation, '2026-08-12', '2026-08-12', null, LeaveRequest::AFTERNOON);
        $request->update(['status' => 'approved', 'is_lwop' => true]);

        $counters = $this->counters($employee, $period);

        $this->assertSame(0.5, (float) $counters['days_lwop']);

        // 10,000 half-salary over 11 scheduled days is 909.09 a day, so half is 454.55.
        $slip = (new \App\Services\Payroll\PayslipCalculator(
            (new \App\Services\Payroll\StatutoryDeductionCalculator)->preload('2026-08-30')
        ))->calculate($employee, $counters, 'second');

        $this->assertSame(454.55, $slip['absence_deduction']);
    }

    #[Test]
    public function missing_the_half_they_were_due_in_is_half_an_absence(): void
    {
        $period = $this->period();
        $employee = $this->regularEmployee();
        $days = $this->workingDays($employee, $period);
        $this->fillAttendance($employee, $period, absentOn: ['2026-08-12']);
        $this->assertContains('2026-08-12', $days);

        $request = $this->leave->submit($employee, $this->vacation, '2026-08-12', '2026-08-12', null, LeaveRequest::MORNING);
        $request->update(['status' => 'approved']);

        $counters = $this->counters($employee, $period);

        $this->assertSame(0.5, (float) $counters['days_absent']);
        $this->assertSame(0.5, (float) $counters['days_on_paid_leave']);
    }

    #[Test]
    public function half_a_graveyard_night_earns_half_the_night_differential(): void
    {
        $period = $this->period();
        $employee = $this->regularEmployee('graveyard');
        $this->fillAttendance($employee, $period);

        $request = $this->leave->submit($employee, $this->vacation, '2026-08-12', '2026-08-12', null, LeaveRequest::MORNING);
        $request->update(['status' => 'approved']);

        $counters = $this->counters($employee, $period);

        // Ten full nights of 480 minutes, and one worth half that.
        $this->assertSame((10 * 480) + 240, $counters['night_diff_minutes']);
    }

    #[Test]
    public function whole_day_leave_is_untouched(): void
    {
        $period = $this->period();
        $employee = $this->regularEmployee();
        $this->fillAttendance($employee, $period, absentOn: ['2026-08-12']);

        $request = $this->leave->submit($employee, $this->vacation, '2026-08-12', '2026-08-12', null);
        $request->update(['status' => 'approved']);

        $counters = $this->counters($employee, $period);

        $this->assertSame(1.0, (float) $counters['days_on_paid_leave']);
        $this->assertSame(0.0, (float) $counters['days_absent']);
    }
}
