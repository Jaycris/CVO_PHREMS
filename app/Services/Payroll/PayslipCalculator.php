<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayrollSetting;

/**
 * Turns one employee's cutoff counters into the money lines of a payslip.
 *
 * Pure computation — nothing here reads or writes the database beyond the
 * settings map and the statutory calculator handed in already loaded. That is
 * what makes each line separately checkable against the company's spreadsheet.
 *
 * Rounding policy, because it is the difference between a payslip that adds up
 * and one that is off by a centavo:
 *
 *   Rates are carried unrounded through every multiplication.
 *   Each money line is rounded to two decimals the moment it becomes a line.
 *   Totals are the sum of already-rounded lines, never a rounded sum of
 *   unrounded values.
 *
 * The payslip therefore visibly adds up when read down the page.
 */
class PayslipCalculator
{
    public function __construct(
        protected StatutoryDeductionCalculator $statutory,
    ) {}

    /**
     * @param  array<string, mixed>  $counters  from AttendanceAggregator
     * @return array<string, mixed>
     */
    public function calculate(Employee $employee, array $counters, string $cutoff): array
    {
        $dailyRate = $employee->dailyRate();
        $hourlyRate = $employee->hourlyRate();
        $minuteRate = $employee->minuteRate();

        // --- earnings -------------------------------------------------------

        $basicEarnings = $this->basicEarnings($employee);
        $absenceDeduction = $this->absenceDeduction($dailyRate, $counters);
        $lateDeduction = $this->lateDeduction($minuteRate, $counters);
        $undertimeDeduction = $this->undertimeDeduction($minuteRate, $counters);
        $overBreakDeduction = $this->overBreakDeduction($minuteRate, $counters);

        // What the employee actually earned in basic pay this cutoff. This is
        // the figure 13th month sums, which is why absence comes off it here
        // and not further down with the other deductions.
        $basicEarned = round($basicEarnings - $absenceDeduction, 2);

        $overtimePay = $this->overtimePay($hourlyRate, $counters);
        $nightDifferentialPay = $this->nightDifferentialPay($employee, $counters);
        $allowance = $this->allowance($employee);

        $grossPay = round($basicEarned + $overtimePay + $nightDifferentialPay + $allowance, 2);

        // --- statutory ------------------------------------------------------

        $sss = $this->statutory->sss($employee, $cutoff);
        $philHealth = $this->statutory->philHealth($employee, $cutoff);
        $pagIbig = $this->statutory->pagIbig($employee, $cutoff);

        $sssEmployee = round($sss['employee'] + $sss['employee_mpf'], 2);
        $contributions = round($sssEmployee + $philHealth['employee'] + $pagIbig['employee'], 2);

        // Government contributions are deducted from taxable income before tax
        // is worked out — that is what makes them tax-exempt.
        $taxableIncome = $this->taxableIncome($basicEarned, $overtimePay, $nightDifferentialPay, $allowance, $employee, $contributions, $lateDeduction, $undertimeDeduction, $overBreakDeduction);
        $withholdingTax = $this->statutory->withholdingTax($employee, $taxableIncome);

        // --- net ------------------------------------------------------------

        $totalDeductions = round(
            $lateDeduction + $undertimeDeduction + $overBreakDeduction
            + $contributions + $withholdingTax,
            2
        );

        $netPay = round($grossPay - $totalDeductions, 2);

        return [
            'daily_rate' => round($dailyRate, 4),
            'hourly_rate' => round($hourlyRate, 4),
            'minute_rate' => round($minuteRate, 4),

            'basic_pay' => $basicEarnings,
            'absence_deduction' => $absenceDeduction,
            'basic_earned' => $basicEarned,
            'overtime_pay' => $overtimePay,
            'night_differential_pay' => $nightDifferentialPay,
            'allowance' => $allowance,
            'gross_pay' => $grossPay,

            'late_deduction' => $lateDeduction,
            'undertime_deduction' => $undertimeDeduction,
            'over_break_deduction' => $overBreakDeduction,

            'sss_employee' => $sssEmployee,
            'sss_employer' => round($sss['employer'] + $sss['employer_mpf'], 2),
            'sss_employee_compensation' => $sss['employee_compensation'],
            'philhealth_employee' => $philHealth['employee'],
            'philhealth_employer' => $philHealth['employer'],
            'pagibig_employee' => $pagIbig['employee'],
            'pagibig_employer' => $pagIbig['employer'],
            'total_contributions' => $contributions,

            'taxable_income' => $taxableIncome,
            'withholding_tax' => $withholdingTax,

            'total_deductions' => $totalDeductions,
            'net_pay' => $netPay,
        ];
    }

    /**
     * Basic pay is a fixed half of the monthly salary, identical on both
     * cutoffs regardless of whether the period spans 13, 15 or 16 days. Twelve
     * months of payslips therefore add up to exactly the annual salary.
     */
    protected function basicEarnings(Employee $employee): float
    {
        return round((float) $employee->basic_salary / 2, 2);
    }

    /**
     * Unpaid absences come off the fixed half. Leave without pay counts the
     * same as an absence — that is what makes it unpaid.
     */
    protected function absenceDeduction(float $dailyRate, array $counters): float
    {
        $days = ($counters['days_absent'] ?? 0) + ($counters['days_lwop'] ?? 0);

        return round($dailyRate * $days, 2);
    }

    protected function lateDeduction(float $minuteRate, array $counters): float
    {
        return round($minuteRate * ($counters['late_minutes'] ?? 0), 2);
    }

    /**
     * Undertime and over-break are always counted and shown, but deduct nothing
     * unless the company turns them on — some payrolls treat them as a
     * discipline matter rather than a pay matter.
     */
    protected function undertimeDeduction(float $minuteRate, array $counters): float
    {
        if (! PayrollSetting::flag('undertime_deduction_enabled')) {
            return 0.0;
        }

        return round($minuteRate * ($counters['undertime_minutes'] ?? 0), 2);
    }

    protected function overBreakDeduction(float $minuteRate, array $counters): float
    {
        if (! PayrollSetting::flag('overbreak_deduction_enabled')) {
            return 0.0;
        }

        return round($minuteRate * ($counters['over_break_minutes'] ?? 0), 2);
    }

    /**
     * Overtime pays a flat hourly rate with no premium, rest day or not.
     *
     * This is deliberate and matches the company's current payroll. A future
     * maintainer will be tempted to "fix" it by adding the statutory 1.25x or
     * 1.30x multipliers — that would change everyone's pay, so it is a decision
     * for the company rather than a correction.
     */
    protected function overtimePay(float $hourlyRate, array $counters): float
    {
        return round($hourlyRate * ($counters['overtime_hours'] ?? 0), 2);
    }

    /**
     * A flat amount for each day worked on a shift overlapping the night
     * window. Day shifts earn nothing.
     *
     * The divisor is a setting rather than a constant because the company's
     * spreadsheet and this formula do not yet agree to the centavo — see the
     * note on night_diff_divisor in Payroll Settings.
     */
    protected function nightDifferentialPay(Employee $employee, array $counters): float
    {
        $days = $counters['night_diff_days'] ?? 0;

        if ($days === 0) {
            return 0.0;
        }

        $divisor = PayrollSetting::number('night_diff_divisor', 22);
        $rate = PayrollSetting::number('night_diff_rate', 0.10);

        if ($divisor <= 0) {
            return 0.0;
        }

        return round(((float) $employee->basic_salary / $divisor) * $rate * $days, 2);
    }

    /** Allowance is paid in full each cutoff and is not reduced by absences. */
    protected function allowance(Employee $employee): float
    {
        return round((float) ($employee->allowance ?? 0), 2);
    }

    /**
     * Taxable income is gross less the government contributions, which are
     * exempt, and less the time-based deductions, which reduce what was
     * actually earned.
     *
     * Allowance is only included when the employee's record marks it taxable —
     * de minimis benefits are exempt, and that is the usual BPO setup.
     */
    protected function taxableIncome(
        float $basicEarned,
        float $overtimePay,
        float $nightDifferentialPay,
        float $allowance,
        Employee $employee,
        float $contributions,
        float $lateDeduction,
        float $undertimeDeduction,
        float $overBreakDeduction,
    ): float {
        $taxable = $basicEarned + $overtimePay + $nightDifferentialPay;

        if ($employee->allowance_taxable) {
            $taxable += $allowance;
        }

        $taxable -= $contributions + $lateDeduction + $undertimeDeduction + $overBreakDeduction;

        return round(max(0, $taxable), 2);
    }
}
