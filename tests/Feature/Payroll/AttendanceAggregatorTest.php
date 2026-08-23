<?php

namespace Tests\Feature\Payroll;

use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Services\Payroll\AttendanceAggregator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * Turning a cutoff's attendance into the counters a payslip is built from.
 *
 * This is where a wrong number starts. A day classified as an absence rather
 * than a rest day costs someone a day's pay, and nothing downstream can tell.
 */
class AttendanceAggregatorTest extends PayrollTestCase
{
    protected function aggregate(Employee $employee, array $period): array
    {
        return (new AttendanceAggregator)
            ->aggregate(collect([$employee]), $period['start'], $period['end'])[$employee->id];
    }

    #[Test]
    public function a_full_period_of_attendance_counts_every_scheduled_day(): void
    {
        $period = $this->period();
        $employee = $this->makeEmployee();
        $filled = $this->fillAttendance($employee, $period);

        $counters = $this->aggregate($employee, $period);

        $this->assertSame(count($filled), $counters['days_expected']);
        $this->assertSame(count($filled), $counters['days_present']);
        $this->assertSame(0, $counters['days_absent']);
        $this->assertSame(0, $counters['late_minutes']);
    }

    #[Test]
    public function a_scheduled_day_with_no_attendance_is_an_absence(): void
    {
        $period = $this->period();
        $employee = $this->makeEmployee();
        $days = $this->workingDays($employee, $period);

        $this->fillAttendance($employee, $period, absentOn: [$days[2], $days[5]]);

        $counters = $this->aggregate($employee, $period);

        $this->assertSame(2, $counters['days_absent']);
        $this->assertSame(count($days) - 2, $counters['days_present']);
    }

    #[Test]
    public function a_rest_day_is_never_an_absence(): void
    {
        $period = $this->period();
        $employee = $this->makeEmployee();
        $this->fillAttendance($employee, $period);

        $counters = $this->aggregate($employee, $period);

        // Aug 11-25 has four weekend days.
        $this->assertGreaterThan(0, $counters['days_rest']);
        $this->assertSame(0, $counters['days_absent']);
    }

    #[Test]
    public function working_a_rest_day_does_not_count_as_a_day_present(): void
    {
        $period = $this->period();
        $employee = $this->makeEmployee();
        $this->fillAttendance($employee, $period);

        // A Saturday, outside the schedule's work days.
        $saturday = collect($this->periods->datesIn($period['start'], $period['end']))
            ->first(fn ($d) => Carbon::parse($d)->isSaturday());

        AttendanceDay::create([
            'employee_id' => $employee->id,
            'work_date' => $saturday,
            'time_in' => $saturday . ' 09:00:00',
            'time_out' => $saturday . ' 18:00:00',
        ]);

        $counters = $this->aggregate($employee, $period);

        // Basic pay is a fixed half of the salary and already covers the
        // scheduled days, so counting a rest day again would pay it twice.
        // Rest day work is paid entirely as approved overtime.
        $this->assertSame(count($this->workingDays($employee, $period)), $counters['days_present']);
    }

    #[Test]
    public function lateness_is_measured_against_the_schedule(): void
    {
        $period = $this->period();
        $employee = $this->makeEmployee();
        $days = $this->workingDays($employee, $period);

        $this->fillAttendance($employee, $period, absentOn: [$days[0]]);
        AttendanceDay::create([
            'employee_id' => $employee->id,
            'work_date' => $days[0],
            'time_in' => $days[0] . ' 09:45:00',
            'time_out' => $days[0] . ' 18:00:00',
        ]);

        $this->assertSame(45, $this->aggregate($employee, $period)['late_minutes']);
    }

    #[Test]
    public function a_grace_period_forgives_lateness_inside_it_and_charges_it_in_full_outside(): void
    {
        $this->setPayrollSetting('late_grace_minutes', '15');

        $period = $this->period();
        $employee = $this->makeEmployee();
        $days = $this->workingDays($employee, $period);

        $this->fillAttendance($employee, $period, absentOn: [$days[0], $days[1]]);

        // 14 minutes late — inside the grace, so nothing is charged.
        AttendanceDay::create([
            'employee_id' => $employee->id,
            'work_date' => $days[0],
            'time_in' => $days[0] . ' 09:14:00',
            'time_out' => $days[0] . ' 18:00:00',
        ]);

        // 20 minutes late — past the grace, so all 20 count, not 5.
        AttendanceDay::create([
            'employee_id' => $employee->id,
            'work_date' => $days[1],
            'time_in' => $days[1] . ' 09:20:00',
            'time_out' => $days[1] . ' 18:00:00',
        ]);

        $this->assertSame(20, $this->aggregate($employee, $period)['late_minutes']);
    }

    #[Test]
    public function without_a_grace_period_a_single_minute_counts(): void
    {
        $this->setPayrollSetting('late_grace_minutes', '0');

        $period = $this->period();
        $employee = $this->makeEmployee();
        $days = $this->workingDays($employee, $period);

        $this->fillAttendance($employee, $period, absentOn: [$days[0]]);
        AttendanceDay::create([
            'employee_id' => $employee->id,
            'work_date' => $days[0],
            'time_in' => $days[0] . ' 09:01:00',
            'time_out' => $days[0] . ' 18:00:00',
        ]);

        $this->assertSame(1, $this->aggregate($employee, $period)['late_minutes']);
    }

    #[Test]
    public function approved_paid_leave_is_not_an_absence(): void
    {
        $period = $this->period();
        $employee = $this->makeEmployee();
        $days = $this->workingDays($employee, $period);
        $this->fillAttendance($employee, $period, absentOn: [$days[3]]);

        LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => LeaveType::factory()->create()->id,
            'start_date' => $days[3],
            'end_date' => $days[3],
            'days_requested' => 1,
            'status' => 'approved',
            'is_lwop' => false,
            'reason' => 'Test',
        ]);

        $counters = $this->aggregate($employee, $period);

        $this->assertSame(1, $counters['days_on_paid_leave']);
        $this->assertSame(0, $counters['days_absent']);
    }

    #[Test]
    public function approved_leave_without_pay_is_counted_separately(): void
    {
        $period = $this->period();
        $employee = $this->makeEmployee();
        $days = $this->workingDays($employee, $period);
        $this->fillAttendance($employee, $period, absentOn: [$days[3]]);

        LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => LeaveType::factory()->create()->id,
            'start_date' => $days[3],
            'end_date' => $days[3],
            'days_requested' => 1,
            'status' => 'approved',
            'is_lwop' => true,
            'reason' => 'Test',
        ]);

        $counters = $this->aggregate($employee, $period);

        $this->assertSame(1, $counters['days_lwop']);
        $this->assertSame(0, $counters['days_absent']);
    }

    #[Test]
    public function only_approved_overtime_is_counted(): void
    {
        $period = $this->period();
        $employee = $this->makeEmployee();
        $days = $this->workingDays($employee, $period);
        $this->fillAttendance($employee, $period);

        OvertimeRequest::factory()->approved(2.5)->create([
            'employee_id' => $employee->id,
            'work_date' => $days[1],
            'hours_requested' => 3,
        ]);

        OvertimeRequest::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => $days[2],
            'hours_requested' => 4,
        ]);

        // Two and a half approved hours, not the three requested, and nothing
        // from the filing still awaiting a decision.
        $this->assertSame(2.5, $this->aggregate($employee, $period)['overtime_hours']);
    }

    #[Test]
    public function a_graveyard_shift_earns_night_differential_and_a_day_shift_does_not(): void
    {
        $period = $this->period();

        $night = $this->makeEmployee(20000, 'graveyard');
        $day = $this->makeEmployee(20000, 'day');
        $this->fillAttendance($night, $period);
        $this->fillAttendance($day, $period);

        $this->assertGreaterThan(0, $this->aggregate($night, $period)['night_diff_days']);
        $this->assertSame(0, $this->aggregate($day, $period)['night_diff_days']);
    }

    #[Test]
    public function days_before_the_hire_date_are_not_counted_against_anyone(): void
    {
        $period = $this->period(2026, 8, 'second');   // Aug 11-25
        $employee = $this->makeEmployee(20000, 'day', ['hire_date' => '2026-08-17']);

        $this->fillAttendance($employee, $period);

        $counters = $this->aggregate($employee, $period);

        // Only the working days from the 17th onward are expected of them.
        $this->assertSame(count($this->workingDays($employee, $period)), $counters['days_expected']);
        $this->assertSame(0, $counters['days_absent']);
    }

    #[Test]
    public function a_day_punched_in_but_never_out_is_reported(): void
    {
        $period = $this->period();
        $employee = $this->makeEmployee();
        $days = $this->workingDays($employee, $period);
        $this->fillAttendance($employee, $period, absentOn: [$days[4]]);

        AttendanceDay::factory()->unclosed()->create([
            'employee_id' => $employee->id,
            'work_date' => $days[4],
            'time_in' => $days[4] . ' 09:00:00',
        ]);

        $this->assertSame([$days[4]], $this->aggregate($employee, $period)['unclosed_days']);
    }

    #[Test]
    public function a_day_with_no_schedule_is_reported_rather_than_guessed_at(): void
    {
        $period = $this->period();

        // No schedule assignment at all.
        $employee = Employee::factory()->salary(20000)->create();

        $counters = (new AttendanceAggregator)
            ->aggregate(collect([$employee]), $period['start'], $period['end'])[$employee->id];

        // Treating it as a workday would invent absences; as a rest day would
        // hide them. It is surfaced for preflight instead.
        $this->assertNotEmpty($counters['unscheduled_days']);
        $this->assertSame(0, $counters['days_absent']);
    }

    #[Test]
    public function the_whole_period_costs_a_fixed_number_of_queries_however_many_employees(): void
    {
        $period = $this->period();

        $employees = collect(range(1, 25))->map(function () use ($period) {
            $employee = $this->makeEmployee();
            $this->fillAttendance($employee, $period);

            return $employee;
        });

        DB::enableQueryLog();
        (new AttendanceAggregator)->aggregate($employees, $period['start'], $period['end']);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Attendance, breaks, schedule assignments, leave, overtime, holidays.
        // Asking each attendance row for its own schedule instead would be
        // thousands.
        $this->assertLessThanOrEqual(8, $queries, "the aggregator issued {$queries} queries");
    }
}
