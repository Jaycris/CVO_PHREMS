<?php

namespace Tests\Feature\Payroll;

use App\Models\Payslip;
use App\Services\Payroll\AttendanceAggregator;
use App\Services\Payroll\PayrollService;
use App\Services\Payroll\PayslipCalculator;
use App\Services\Payroll\StatutoryDeductionCalculator;
use PHPUnit\Framework\Attributes\Test;

/**
 * Somebody who was not there for the whole cutoff.
 *
 * Basic pay is a fixed half-month, and days before a hire date are deliberately
 * not absences — so a hire on the 21st was paid eleven days for five days of
 * work, and nothing downstream could tell. The company paid the difference.
 */
class MidCutoffHireTest extends PayrollTestCase
{
    protected function counters(\App\Models\Employee $employee, array $period): array
    {
        return (new AttendanceAggregator)
            ->aggregate(collect([$employee]), $period['start'], $period['end'])[$employee->id];
    }

    protected function calculator(): PayslipCalculator
    {
        return new PayslipCalculator((new StatutoryDeductionCalculator)->preload('2026-09-30'));
    }

    #[Test]
    public function a_hire_on_the_21st_is_paid_for_the_days_they_worked(): void
    {
        // Sep 11-25 2026: 11 weekdays, of which the 21st onwards is 5.
        $period = $this->period(2026, 9, 'second');
        $employee = $this->makeEmployee(20000, 'graveyard', ['hire_date' => '2026-09-21']);
        $this->fillAttendance($employee, $period);

        $counters = $this->counters($employee, $period);

        $this->assertSame(5, $counters['days_expected']);
        $this->assertSame(11, $counters['days_in_cutoff']);

        $slip = $this->calculator()->calculate($employee, $counters, 'second');

        // 10,000 over the cutoff's own 11 days is 909.09 a day; five of them.
        $this->assertSame(4545.45, $slip['basic_pay']);
        $this->assertSame(909.0909, round($slip['daily_rate'], 4));
    }

    #[Test]
    public function an_absence_still_costs_one_day_not_a_fifth_of_the_month(): void
    {
        $period = $this->period(2026, 9, 'second');
        $employee = $this->makeEmployee(20000, 'graveyard', ['hire_date' => '2026-09-21']);
        $days = $this->workingDays($employee, $period);
        $this->fillAttendance($employee, $period, absentOn: [$days[1]]);

        $counters = $this->counters($employee, $period);
        $slip = $this->calculator()->calculate($employee, $counters, 'second');

        $this->assertSame(1.0, (float) $counters['days_absent']);
        $this->assertSame(909.09, $slip['absence_deduction']);
        // Four days worked out of the five they were employed for.
        $this->assertSame(3636.36, round($slip['basic_pay'] - $slip['absence_deduction'], 2));
    }

    #[Test]
    public function somebody_there_for_the_whole_cutoff_is_untouched(): void
    {
        $period = $this->period(2026, 9, 'second');
        $employee = $this->makeEmployee(20000, 'graveyard', ['hire_date' => '2024-01-05']);
        $this->fillAttendance($employee, $period);

        $slip = $this->calculator()->calculate($employee, $this->counters($employee, $period), 'second');

        $this->assertSame(10000.00, $slip['basic_pay']);
    }

    #[Test]
    public function somebody_who_leaves_mid_cutoff_is_paid_to_their_last_day(): void
    {
        $period = $this->period(2026, 9, 'second');
        $employee = $this->makeEmployee(20000, 'graveyard', ['separation_date' => '2026-09-16']);
        $this->fillAttendance($employee, $period);

        $counters = $this->counters($employee, $period);
        $slip = $this->calculator()->calculate($employee, $counters, 'second');

        $this->assertLessThan(11, $counters['days_expected']);
        $this->assertSame(round(909.0909 * $counters['days_expected'], 2), $slip['basic_pay']);
    }

    #[Test]
    public function the_payslip_says_why_the_basic_is_short(): void
    {
        $period = $this->period(2026, 9, 'second');
        $employee = $this->makeEmployee(20000, 'graveyard', ['hire_date' => '2026-09-21']);
        $this->fillAttendance($employee, $period);

        $service = app(PayrollService::class);
        $run = $service->openRun(2026, 9, 'second');
        $service->compute($run, $this->admin);

        $payslip = Payslip::where('employee_id', $employee->id)->sole();

        $this->assertSame(4545.45, (float) $payslip->basic_pay);
        $this->assertSame(11, (int) $payslip->days_in_cutoff);

        $line = $payslip->lines()->where('label', 'Basic pay')->sole();

        $this->assertSame('5 of 11 days — part of this cutoff only', $line->detail);
    }
}
