<?php

namespace Tests\Feature\Payroll;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OffsiteAssignment;
use App\Services\Payroll\AttendanceAggregator;
use App\Services\Payroll\PayslipCalculator;
use App\Services\Payroll\StatutoryDeductionCalculator;
use PHPUnit\Framework\Attributes\Test;

/**
 * Days worked for the company away from the punch clock.
 *
 * The company is at a trade exhibit from the 8th to the 13th and some of the
 * staff are on the booth. Nobody is going to clock in from a stand, and without
 * a record of that every one of those days reads as an absence and takes a
 * day's pay off them — six days of it, from people who were working.
 */
class OffsiteAssignmentTest extends PayrollTestCase
{
    protected function payFor(Employee $employee): array
    {
        $period = $this->period();

        $counters = (new AttendanceAggregator)
            ->aggregate(collect([$employee]), $period['start'], $period['end'])[$employee->id];

        $figures = (new PayslipCalculator(
            (new StatutoryDeductionCalculator)->preload($period['pay_date'])
        ))->calculate($employee, $counters, $period['cutoff']);

        return [$counters, $figures];
    }

    /** @param list<string> $dates */
    protected function assign(Employee $employee, array $dates, string $reason = 'Trade exhibit', ?string $kind = null): OffsiteAssignment
    {
        return OffsiteAssignment::create([
            'employee_id' => $employee->id,
            'start_date' => $dates[0],
            'end_date' => $dates[count($dates) - 1],
            'kind' => $kind ?? OffsiteAssignment::WORKED,
            'reason' => $reason,
        ]);
    }

    #[Test]
    public function a_day_given_off_in_lieu_is_paid_the_same_as_one_worked(): void
    {
        // The money is identical — the difference is only what the record says
        // happened, so that a payslip does not call a rest day work.
        $period = $this->period();
        $employee = $this->makeEmployee(salary: 20000);
        $days = $this->workingDays($employee, $period);

        $this->fillAttendance($employee, $period, absentOn: [$days[0]]);
        $this->assign($employee, [$days[0]], 'Rest day after the exhibit', OffsiteAssignment::DAY_OFF);

        [$counters, $figures] = $this->payFor($employee);

        $this->assertSame(0, $counters['days_absent']);
        $this->assertSame(1, $counters['days_offsite']);
        $this->assertSame(1, $counters['days_offsite_day_off']);
        $this->assertSame(0.0, $figures['absence_deduction']);
    }

    #[Test]
    public function a_day_worked_off_site_is_not_counted_as_a_day_off(): void
    {
        $period = $this->period();
        $employee = $this->makeEmployee(salary: 20000);
        $days = $this->workingDays($employee, $period);

        $this->fillAttendance($employee, $period, absentOn: [$days[0]]);
        $this->assign($employee, [$days[0]]);

        [$counters] = $this->payFor($employee);

        $this->assertSame(1, $counters['days_offsite']);
        $this->assertSame(0, $counters['days_offsite_day_off']);
    }

    #[Test]
    public function days_on_the_booth_are_paid_rather_than_deducted(): void
    {
        $period = $this->period();
        $employee = $this->makeEmployee(salary: 20000);
        $days = $this->workingDays($employee, $period);

        // Three scheduled days at the exhibit, no punches for any of them.
        $exhibit = array_slice($days, 0, 3);
        $this->fillAttendance($employee, $period, absentOn: $exhibit);
        $this->assign($employee, $exhibit);

        [$counters, $figures] = $this->payFor($employee);

        $this->assertSame(0, $counters['days_absent'], 'A day at the exhibit was counted as an absence.');
        $this->assertSame(3, $counters['days_offsite']);
        $this->assertSame(0.0, $figures['absence_deduction']);
        $this->assertSame(10000.0, $figures['basic_earned']);
    }

    #[Test]
    public function those_days_still_count_towards_the_daily_rate(): void
    {
        // Skipping them rather than counting them present would shrink
        // days_expected and quietly raise everybody else's daily rate.
        $period = $this->period();
        $employee = $this->makeEmployee(salary: 20000);
        $days = $this->workingDays($employee, $period);

        $this->fillAttendance($employee, $period, absentOn: [$days[0]]);
        $this->assign($employee, [$days[0]]);

        [$counters] = $this->payFor($employee);

        $this->assertSame(count($days), $counters['days_expected']);
        $this->assertSame(count($days), $counters['days_present']);
    }

    #[Test]
    public function no_night_differential_is_paid_for_them(): void
    {
        /*
         * The exhibit is daytime work whatever the person's usual shift says.
         * Paying a night premium for a day spent on a stand would be inventing
         * a fact nobody recorded.
         */
        $period = $this->period();
        $employee = $this->makeEmployee(salary: 20000, schedule: 'graveyard');
        $days = $this->workingDays($employee, $period);

        $exhibit = array_slice($days, 0, 3);
        $this->fillAttendance($employee, $period, absentOn: $exhibit);
        $this->assign($employee, $exhibit);

        [$counters] = $this->payFor($employee);

        $this->assertSame(3, $counters['days_offsite']);
        $this->assertSame(count($days) - 3, $counters['night_diff_days']);
    }

    #[Test]
    public function no_lateness_is_charged_for_a_day_with_no_clock(): void
    {
        $period = $this->period();
        $employee = $this->makeEmployee(salary: 20000);
        $days = $this->workingDays($employee, $period);

        $this->fillAttendance($employee, $period, absentOn: [$days[0]]);
        $this->assign($employee, [$days[0]]);

        [$counters, $figures] = $this->payFor($employee);

        $this->assertSame(0, $counters['late_minutes']);
        $this->assertSame(0.0, $figures['late_deduction']);
    }

    #[Test]
    public function a_rest_day_inside_the_range_stays_a_rest_day(): void
    {
        // The exhibit runs the 8th to the 13th, which crosses a weekend. Those
        // days were never expected, and paying them would be paying twice.
        $period = $this->period();
        $employee = $this->makeEmployee(salary: 20000);

        $this->fillAttendance($employee, $period);

        $this->assign($employee, [
            $period['start']->toDateString(),
            $period['end']->toDateString(),
        ]);

        [$counters] = $this->payFor($employee);

        $this->assertSame(
            count($this->workingDays($employee, $period)),
            $counters['days_expected'],
            'A rest day inside the range was turned into a working day.',
        );
    }

    #[Test]
    public function leave_filed_for_the_same_day_still_wins(): void
    {
        // Somebody who filed leave for a day they were also listed for spent a
        // credit on purpose. Handing it back is the leave module's business.
        $period = $this->period();
        $employee = $this->makeEmployee(salary: 20000);
        $days = $this->workingDays($employee, $period);

        $type = LeaveType::where('code', 'VL')->first() ?? LeaveType::factory()->create(['code' => 'VL']);

        LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => $days[0],
            'end_date' => $days[0],
            'days_requested' => 1,
            'status' => 'approved',
            'is_lwop' => false,
            'reason' => 'Booked before the exhibit was announced',
        ]);

        $this->fillAttendance($employee, $period, absentOn: [$days[0]]);
        $this->assign($employee, [$days[0]]);

        [$counters] = $this->payFor($employee);

        $this->assertSame(1, $counters['days_on_paid_leave']);
        $this->assertSame(0, $counters['days_offsite']);
    }

    #[Test]
    public function somebody_not_on_the_list_is_unaffected(): void
    {
        $period = $this->period();
        $goes = $this->makeEmployee(salary: 20000);
        $stays = $this->makeEmployee(salary: 20000);
        $days = $this->workingDays($goes, $period);

        $this->fillAttendance($goes, $period, absentOn: [$days[0]]);
        $this->fillAttendance($stays, $period, absentOn: [$days[0]]);
        $this->assign($goes, [$days[0]]);

        [, $goesFigures] = $this->payFor($goes);
        [$staysCounters, $staysFigures] = $this->payFor($stays);

        $this->assertSame(0.0, $goesFigures['absence_deduction']);
        $this->assertSame(1, $staysCounters['days_absent']);
        $this->assertGreaterThan(0, $staysFigures['absence_deduction']);
    }
}
