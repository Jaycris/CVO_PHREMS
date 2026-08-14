<?php

namespace Tests\Feature\Payroll;

use App\Services\Payroll\PayslipCalculator;
use App\Services\Payroll\StatutoryDeductionCalculator;
use PHPUnit\Framework\Attributes\Test;

/**
 * The money lines, one at a time.
 *
 * Every expected figure here is worked out by hand in the test name or the
 * comment, so a failure says what the number should have been rather than only
 * that it changed.
 */
class PayslipCalculatorTest extends PayrollTestCase
{
    protected function calculator(): PayslipCalculator
    {
        return new PayslipCalculator(
            (new StatutoryDeductionCalculator)->preload('2026-08-30')
        );
    }

    /** @param array<string, mixed> $overrides */
    protected function counters(array $overrides = []): array
    {
        return array_merge([
            'days_expected' => 11,
            'days_present' => 11,
            'days_absent' => 0,
            'days_lwop' => 0,
            'days_on_paid_leave' => 0,
            'days_rest' => 4,
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'over_break_minutes' => 0,
            'night_diff_days' => 0,
            'overtime_hours' => 0,
        ], $overrides);
    }

    #[Test]
    public function basic_pay_is_half_the_monthly_salary_whatever_the_period_length(): void
    {
        $employee = $this->makeEmployee(20000);

        foreach ([9, 11, 12] as $days) {
            $slip = $this->calculator()->calculate($employee, $this->counters(['days_expected' => $days, 'days_present' => $days]), 'second');

            $this->assertSame(10000.00, $slip['basic_pay'], "basic pay moved on a {$days}-day cutoff");
        }
    }

    #[Test]
    public function a_day_is_priced_over_the_days_actually_scheduled(): void
    {
        $employee = $this->makeEmployee(20000);

        // 10,000 half-salary over 11 days = 909.09; over 10 days = 1,000.00.
        $eleven = $this->calculator()->calculate($employee, $this->counters(['days_expected' => 11]), 'second');
        $ten = $this->calculator()->calculate($employee, $this->counters(['days_expected' => 10, 'days_present' => 10]), 'second');

        $this->assertSame(909.0909, round($eleven['daily_rate'], 4));
        $this->assertSame(1000.0000, round($ten['daily_rate'], 4));
    }

    #[Test]
    public function missing_every_scheduled_day_leaves_exactly_nothing(): void
    {
        $employee = $this->makeEmployee(20000);

        $slip = $this->calculator()->calculate(
            $employee,
            $this->counters(['days_expected' => 11, 'days_present' => 0, 'days_absent' => 11]),
            'second'
        );

        $this->assertSame(10000.00, $slip['absence_deduction']);
        $this->assertSame(0.00, $slip['basic_earned']);
        $this->assertSame(0.00, $slip['net_pay']);
    }

    #[Test]
    public function leave_without_pay_is_deducted_the_same_as_an_absence(): void
    {
        $employee = $this->makeEmployee(20000);

        $absent = $this->calculator()->calculate($employee, $this->counters(['days_present' => 10, 'days_absent' => 1]), 'second');
        $lwop = $this->calculator()->calculate($employee, $this->counters(['days_present' => 10, 'days_lwop' => 1]), 'second');

        $this->assertSame($absent['absence_deduction'], $lwop['absence_deduction']);
        $this->assertSame(909.09, $absent['absence_deduction']);
    }

    #[Test]
    public function paid_leave_is_not_deducted(): void
    {
        $employee = $this->makeEmployee(20000);

        $slip = $this->calculator()->calculate(
            $employee,
            $this->counters(['days_present' => 10, 'days_on_paid_leave' => 1]),
            'second'
        );

        $this->assertSame(0.00, $slip['absence_deduction']);
        $this->assertSame(10000.00, $slip['basic_earned']);
    }

    #[Test]
    public function lateness_is_charged_per_minute(): void
    {
        $employee = $this->makeEmployee(20000);

        // 909.0909 a day over 8 hours = 113.6364 an hour = 1.8939 a minute.
        // 45 minutes = 85.23.
        $slip = $this->calculator()->calculate($employee, $this->counters(['late_minutes' => 45]), 'second');

        $this->assertSame(85.23, $slip['late_deduction']);
    }

    #[Test]
    public function overtime_pays_a_flat_hourly_rate_with_no_premium(): void
    {
        $employee = $this->makeEmployee(20000);

        // 113.6364 an hour x 3 = 340.91, rest day or not. The company pays no
        // multiplier, and a future change adding one must break this test.
        $slip = $this->calculator()->calculate($employee, $this->counters(['overtime_hours' => 3]), 'second');

        $this->assertSame(340.91, $slip['overtime_pay']);
    }

    #[Test]
    public function a_day_shift_earns_no_night_differential(): void
    {
        $employee = $this->makeEmployee(20000);

        $slip = $this->calculator()->calculate($employee, $this->counters(['night_diff_days' => 0]), 'second');

        $this->assertSame(0.00, $slip['night_differential_pay']);
    }

    #[Test]
    public function night_differential_is_paid_per_qualifying_day(): void
    {
        $employee = $this->makeEmployee(20000);

        // (20,000 / 22) x 10% x 10 days = 909.09.
        $slip = $this->calculator()->calculate($employee, $this->counters(['night_diff_days' => 10]), 'second');

        $this->assertSame(909.09, $slip['night_differential_pay']);
    }

    #[Test]
    public function undertime_and_over_break_are_counted_but_not_charged_by_default(): void
    {
        $employee = $this->makeEmployee(20000);

        $slip = $this->calculator()->calculate(
            $employee,
            $this->counters(['undertime_minutes' => 30, 'over_break_minutes' => 20]),
            'second'
        );

        $this->assertSame(0.00, $slip['undertime_deduction']);
        $this->assertSame(0.00, $slip['over_break_deduction']);
    }

    #[Test]
    public function turning_the_settings_on_makes_undertime_and_over_break_deduct(): void
    {
        $this->setPayrollSetting('undertime_deduction_enabled', '1');
        $this->setPayrollSetting('overbreak_deduction_enabled', '1');

        $employee = $this->makeEmployee(20000);

        // 1.8939 a minute: 30 min = 56.82, 20 min = 37.88.
        $slip = $this->calculator()->calculate(
            $employee,
            $this->counters(['undertime_minutes' => 30, 'over_break_minutes' => 20]),
            'second'
        );

        $this->assertSame(56.82, $slip['undertime_deduction']);
        $this->assertSame(37.88, $slip['over_break_deduction']);
    }

    #[Test]
    public function allowance_is_paid_in_full_and_is_not_taxed_by_default(): void
    {
        $employee = $this->makeEmployee(20000, 'day', ['allowance' => 2000]);

        $slip = $this->calculator()->calculate($employee, $this->counters(), 'second');

        $this->assertSame(2000.00, $slip['allowance']);
        $this->assertSame(12000.00, $slip['gross_pay']);
        // Taxable income excludes it while allowance_taxable is false.
        $this->assertSame(10000.00, $slip['taxable_income']);
    }

    #[Test]
    public function a_taxable_allowance_is_included_in_taxable_income(): void
    {
        $employee = $this->makeEmployee(20000, 'day', ['allowance' => 2000, 'allowance_taxable' => true]);

        $slip = $this->calculator()->calculate($employee, $this->counters(), 'second');

        $this->assertSame(12000.00, $slip['taxable_income']);
    }

    #[Test]
    public function the_payslip_adds_up(): void
    {
        $employee = $this->makeEmployee(20000, 'day', ['allowance' => 1500]);
        $this->enableStatutory();

        $slip = $this->calculator()->calculate(
            $employee,
            $this->counters([
                'days_present' => 9, 'days_absent' => 2,
                'late_minutes' => 37, 'overtime_hours' => 4.5, 'night_diff_days' => 6,
            ]),
            'second'
        );

        $earnings = $slip['basic_earned'] + $slip['overtime_pay']
            + $slip['night_differential_pay'] + $slip['allowance'];

        $deductions = $slip['late_deduction'] + $slip['undertime_deduction']
            + $slip['over_break_deduction'] + $slip['total_contributions'] + $slip['withholding_tax'];

        $this->assertSame(round($earnings, 2), $slip['gross_pay'], 'gross is not the sum of its lines');
        $this->assertSame(round($deductions, 2), $slip['total_deductions'], 'deductions are not the sum of their lines');
        $this->assertSame(round($slip['gross_pay'] - $slip['total_deductions'], 2), $slip['net_pay'], 'net does not reconcile');
    }
}
