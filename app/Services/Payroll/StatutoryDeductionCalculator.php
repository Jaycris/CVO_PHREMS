<?php

namespace App\Services\Payroll;

use App\Models\BirWithholdingBracket;
use App\Models\Employee;
use App\Models\PagibigRate;
use App\Models\PhilhealthRate;
use App\Models\SssBracket;
use App\Models\StatutoryContributionSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The four government deductions.
 *
 * Two rules run through all of it:
 *
 * The contribution base is monthly basic salary, not what the employee earned
 * this cutoff. Someone who was absent a week still owes the same SSS as a full
 * month — the tables are published against monthly pay, and prorating them
 * would under-remit and leave the company liable for the difference.
 *
 * The whole month's contribution comes out of one cutoff, chosen per type in
 * the statutory settings, rather than being halved across both. That is how the
 * company already runs payroll.
 *
 * Every table is loaded once per run via preload() and read from memory
 * afterwards, so a hundred employees cost four queries rather than four hundred.
 */
class StatutoryDeductionCalculator
{
    protected ?Carbon $loadedFor = null;

    protected Collection $sssBrackets;

    protected ?PhilhealthRate $philhealthRate = null;

    protected Collection $pagibigRates;

    protected Collection $birBrackets;

    /** @var array<string, StatutoryContributionSetting> */
    protected array $settings = [];

    public function __construct()
    {
        $this->sssBrackets = collect();
        $this->pagibigRates = collect();
        $this->birBrackets = collect();
    }

    /** Loads every rate table as of a date. Call once per payroll run. */
    public function preload(Carbon|string $asOf): static
    {
        $date = Carbon::parse($asOf)->startOfDay();

        if ($this->loadedFor?->equalTo($date)) {
            return $this;
        }

        $this->loadedFor = $date;

        $this->sssBrackets = SssBracket::effectiveOn($date)->orderBy('salary_from')->get();
        $this->philhealthRate = PhilhealthRate::effectiveOn($date)->orderByDesc('effective_from')->first();
        $this->pagibigRates = PagibigRate::effectiveOn($date)->orderBy('salary_from')->get();
        $this->birBrackets = BirWithholdingBracket::effectiveOn($date)
            ->where('period', 'semi_monthly')
            ->orderBy('income_from')
            ->get();

        $this->settings = StatutoryContributionSetting::all()->keyBy('code')->all();

        return $this;
    }

    /**
     * Whether a contribution comes out of this cutoff at all. A type targeted
     * at the second cutoff is simply zero on the first.
     */
    public function appliesToCutoff(string $code, string $cutoff): bool
    {
        $setting = $this->settings[$code] ?? null;

        if (! $setting || ! $setting->is_active) {
            return false;
        }

        return $setting->deduct_on_cutoff === $cutoff;
    }

    /**
     * @return array{employee: float, employer: float, employee_mpf: float, employer_mpf: float, employee_compensation: float, monthly_salary_credit: float}
     */
    public function sss(Employee $employee, string $cutoff): array
    {
        $zero = [
            'employee' => 0.0, 'employer' => 0.0,
            'employee_mpf' => 0.0, 'employer_mpf' => 0.0,
            'employee_compensation' => 0.0, 'monthly_salary_credit' => 0.0,
        ];

        if (! $employee->sss_enrolled || ! $this->appliesToCutoff('sss', $cutoff)) {
            return $zero;
        }

        $bracket = $this->findSssBracket((float) $employee->basic_salary);

        if (! $bracket) {
            return $zero;
        }

        return [
            'employee' => (float) $bracket->employee_share,
            'employer' => (float) $bracket->employer_share,
            'employee_mpf' => (float) $bracket->employee_mpf_share,
            'employer_mpf' => (float) $bracket->employer_mpf_share,
            'employee_compensation' => (float) $bracket->employee_compensation,
            'monthly_salary_credit' => (float) $bracket->monthly_salary_credit,
        ];
    }

    /** Total taken from the employee's pay for SSS, provident fund included. */
    public function sssEmployeeTotal(Employee $employee, string $cutoff): float
    {
        $sss = $this->sss($employee, $cutoff);

        return round($sss['employee'] + $sss['employee_mpf'], 2);
    }

    /** @return array{employee: float, employer: float, premium: float} */
    public function philHealth(Employee $employee, string $cutoff): array
    {
        $zero = ['employee' => 0.0, 'employer' => 0.0, 'premium' => 0.0];

        if (! $employee->philhealth_enrolled || ! $this->appliesToCutoff('philhealth', $cutoff)) {
            return $zero;
        }

        if (! $this->philhealthRate) {
            return $zero;
        }

        $rate = $this->philhealthRate;
        $premium = round($rate->premiumBase((float) $employee->basic_salary) * (float) $rate->premium_rate, 2);
        $employeeShare = round($premium * (float) $rate->employee_share_ratio, 2);

        return [
            'employee' => $employeeShare,
            // The employer carries the remainder rather than its own rounded
            // half, so the two shares always add back to the premium remitted.
            'employer' => round($premium - $employeeShare, 2),
            'premium' => $premium,
        ];
    }

    /** @return array{employee: float, employer: float} */
    public function pagIbig(Employee $employee, string $cutoff): array
    {
        $zero = ['employee' => 0.0, 'employer' => 0.0];

        if (! $employee->pagibig_enrolled || ! $this->appliesToCutoff('pagibig', $cutoff)) {
            return $zero;
        }

        $rate = $this->findPagibigRate((float) $employee->basic_salary);

        if (! $rate) {
            return $zero;
        }

        $base = $rate->contributionBase((float) $employee->basic_salary);

        return [
            'employee' => round($base * (float) $rate->employee_rate, 2),
            'employer' => round($base * (float) $rate->employer_rate, 2),
        ];
    }

    /**
     * Withholding tax on this cutoff's taxable income.
     *
     * Unlike the contributions above this is not targeted at one cutoff — tax
     * is withheld on what was actually paid, every payslip.
     */
    public function withholdingTax(Employee $employee, float $taxableIncome): float
    {
        if (! $employee->bir_withholding_enrolled) {
            return 0.0;
        }

        $setting = $this->settings['bir'] ?? null;

        if ($setting && ! $setting->is_active) {
            return 0.0;
        }

        if ($taxableIncome <= 0) {
            return 0.0;
        }

        $bracket = $this->findBirBracket($taxableIncome);

        return $bracket ? $bracket->taxFor($taxableIncome) : 0.0;
    }

    /** Everything withheld from the employee, other than tax. */
    public function employeeContributionsTotal(Employee $employee, string $cutoff): float
    {
        return round(
            $this->sssEmployeeTotal($employee, $cutoff)
            + $this->philHealth($employee, $cutoff)['employee']
            + $this->pagIbig($employee, $cutoff)['employee'],
            2
        );
    }

    protected function findSssBracket(float $monthlySalary): ?SssBracket
    {
        $this->guardLoaded();

        $match = $this->sssBrackets->first(fn (SssBracket $b) => $monthlySalary >= (float) $b->salary_from
            && ($b->salary_to === null || $monthlySalary <= (float) $b->salary_to));

        // Pay under the lowest bracket clamps up; pay over the highest clamps
        // down. A salary outside the published table still owes a contribution.
        return $match
            ?? ($monthlySalary < (float) ($this->sssBrackets->first()?->salary_from ?? 0)
                ? $this->sssBrackets->first()
                : $this->sssBrackets->last());
    }

    protected function findPagibigRate(float $monthlySalary): ?PagibigRate
    {
        $this->guardLoaded();

        return $this->pagibigRates->first(fn (PagibigRate $r) => $monthlySalary >= (float) $r->salary_from
            && ($r->salary_to === null || $monthlySalary <= (float) $r->salary_to))
            ?? $this->pagibigRates->last();
    }

    protected function findBirBracket(float $taxableIncome): ?BirWithholdingBracket
    {
        $this->guardLoaded();

        return $this->birBrackets->last(fn (BirWithholdingBracket $b) => $taxableIncome >= (float) $b->income_from
            && ($b->income_to === null || $taxableIncome <= (float) $b->income_to));
    }

    protected function guardLoaded(): void
    {
        abort_if(
            $this->loadedFor === null,
            500,
            'Statutory rates were not loaded. Call preload() with the payroll date first.'
        );
    }
}
