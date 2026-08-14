<?php

namespace Database\Seeders;

use App\Models\BirWithholdingBracket;
use App\Models\PagibigRate;
use App\Models\PayrollSetting;
use App\Models\PhilhealthRate;
use App\Models\SssBracket;
use App\Models\StatutoryContributionSetting;
use App\Services\Payroll\SssTableGenerator;
use Illuminate\Database\Seeder;

/**
 * ⚠ THESE FIGURES MUST BE CHECKED AGAINST THE CURRENT CIRCULARS BEFORE THE
 *   FIRST REAL PAYROLL RUN.
 *
 * SSS, PhilHealth, Pag-IBIG and BIR revise their tables by circular, often
 * yearly. What is seeded here is a starting point so the system computes
 * something sensible on day one — it is not a substitute for the issuances.
 * The tables are admin-editable precisely so correcting them is a data change
 * and not a code change.
 *
 * Reseeding is safe: rows are matched on their natural key and updated, so
 * nothing is duplicated and no history is lost.
 */
class StatutorySeeder extends Seeder
{
    protected string $effectiveFrom = '2025-01-01';

    public function run(): void
    {
        $this->seedContributionSettings();
        $this->seedSssBrackets();
        $this->seedPhilhealthRate();
        $this->seedPagibigRates();
        $this->seedBirBrackets();
        $this->seedPayrollSettings();
    }

    /**
     * All four start switched OFF.
     *
     * The company does not yet deduct any of them, so defaulting to on would
     * silently take money off every payslip from the first run. The rates are
     * still loaded and ready — turning each one on is a checkbox on the Payroll
     * Settings page, on whatever date the company starts remitting.
     *
     * The cutoff each is targeted at only matters once it is switched on: SSS
     * on the first and the rest on the second spreads them so neither payslip
     * carries all of them at once.
     */
    protected function seedContributionSettings(): void
    {
        $targets = [
            'sss' => 'first',
            'philhealth' => 'second',
            'pagibig' => 'second',
            'bir' => 'second',
        ];

        foreach ($targets as $code => $cutoff) {
            StatutoryContributionSetting::firstOrCreate(
                ['code' => $code],
                ['deduct_on_cutoff' => $cutoff, 'is_active' => false]
            );
        }
    }

    /**
     * Generated from the published rule rather than transcribed row by row,
     * because a 30-row table typed by hand is 30 chances to fat-finger a peso.
     *
     * The rule: brackets step every 500 of Monthly Salary Credit from 5,000 to
     * 35,000, each covering the 500 centred on it. The total rate is split 5%
     * employee / 10% employer. MSC up to 20,000 is regular SSS; anything above
     * that goes to the provident fund (WISP), reported separately. The employer
     * also pays a small Employee Compensation premium, which never touches the
     * employee's pay.
     */
    protected function seedSssBrackets(): void
    {
        // Only seed if nothing is loaded yet — reseeding must not wipe out a
        // table HR has since regenerated with their own figures.
        if (SssBracket::whereDate('effective_from', $this->effectiveFrom)->exists()) {
            return;
        }

        $generator = new SssTableGenerator();
        $generator->generate($generator->defaults(), $this->effectiveFrom);
    }

    protected function seedPhilhealthRate(): void
    {
        // firstOrCreate, not updateOrCreate — a rate HR has revised must survive
        // a reseed.
        PhilhealthRate::firstOrCreate(
            ['effective_from' => $this->effectiveFrom],
            [
                'premium_rate' => 0.05,
                'salary_floor' => 10000,
                'salary_ceiling' => 100000,
                'employee_share_ratio' => 0.5,
                'effective_to' => null,
            ]
        );
    }

    protected function seedPagibigRates(): void
    {
        $bands = [
            ['from' => 0, 'to' => 1500, 'employee' => 0.01, 'employer' => 0.02],
            ['from' => 1500.01, 'to' => null, 'employee' => 0.02, 'employer' => 0.02],
        ];

        foreach ($bands as $band) {
            PagibigRate::firstOrCreate(
                ['salary_from' => $band['from'], 'effective_from' => $this->effectiveFrom],
                [
                    'salary_to' => $band['to'],
                    'employee_rate' => $band['employee'],
                    'employer_rate' => $band['employer'],
                    // Rates apply to this base, not to actual pay — which is
                    // what caps both sides at 100 a month.
                    'max_contribution_base' => 5000,
                    'effective_to' => null,
                ]
            );
        }
    }

    /** The revised withholding table, semi-monthly column. */
    protected function seedBirBrackets(): void
    {
        $brackets = [
            ['from' => 0,       'to' => 10416.99,  'base' => 0,        'rate' => 0],
            ['from' => 10417,   'to' => 16666.99,  'base' => 0,        'rate' => 0.15],
            ['from' => 16667,   'to' => 33332.99,  'base' => 937.50,   'rate' => 0.20],
            ['from' => 33333,   'to' => 83332.99,  'base' => 4270.70,  'rate' => 0.25],
            ['from' => 83333,   'to' => 333332.99, 'base' => 16770.70, 'rate' => 0.30],
            ['from' => 333333,  'to' => null,      'base' => 91770.70, 'rate' => 0.35],
        ];

        foreach ($brackets as $bracket) {
            BirWithholdingBracket::firstOrCreate(
                ['period' => 'semi_monthly', 'income_from' => $bracket['from'], 'effective_from' => $this->effectiveFrom],
                [
                    'income_to' => $bracket['to'],
                    'base_tax' => $bracket['base'],
                    'excess_rate' => $bracket['rate'],
                    'effective_to' => null,
                ]
            );
        }
    }

    /** Company policy figures, as opposed to anything the government sets. */
    protected function seedPayrollSettings(): void
    {
        $settings = [
            [
                'key' => 'late_grace_minutes',
                'value' => '0',
                'label' => 'Minutes forgiven before someone counts as late',
                'description' => 'Minutes after the shift start that are forgiven entirely. With 15, arriving 14 minutes late is not late; arriving 20 minutes late is 20 minutes, not 5. Set to 0 for no grace period.',
                'type' => 'integer',
                'group' => 'Grace Period',
            ],
            [
                'key' => 'daily_rate_basis',
                'value' => 'actual',
                'label' => 'How a day\'s pay is worked out',
                'description' => 'Actual working days recalculates each cutoff from the days scheduled, so being absent all period always deducts exactly that period\'s pay. Fixed uses the same number every month instead.',
                'type' => 'choice',
                'group' => 'Daily and Hourly Rates',
            ],
            [
                'key' => 'daily_rate_divisor',
                // 22, not 30: over 30, an employee absent every working day of a
                // 22-day month still keeps about a quarter of their salary,
                // because 22 x (salary/30) is less than the salary. Over 22 the
                // same absences deduct the whole month and land on zero.
                'value' => '22',
                'label' => 'Days per month used to price a day',
                'description' => 'Divides the monthly salary to get a day\'s pay, which prices absences, lateness and overtime. 22 counts working days, so being absent every working day deducts exactly one month. 30 counts calendar days and leaves part of the salary behind.',
                'type' => 'decimal',
                'group' => 'Daily and Hourly Rates',
            ],
            [
                'key' => 'hours_per_day',
                'value' => '8',
                'label' => 'Hours in a working day',
                'description' => 'Divides a day\'s pay to get an hour\'s pay, which prices overtime and lateness.',
                'type' => 'decimal',
                'group' => 'Daily and Hourly Rates',
            ],
            [
                'key' => 'night_diff_divisor',
                'value' => '22',
                'label' => 'Night differential divisor',
                'description' => 'Working days per month used to price a night differential day.',
                'type' => 'decimal',
                'group' => 'Night Differential',
            ],
            [
                'key' => 'night_diff_rate',
                'value' => '0.10',
                'label' => 'Night differential rate',
                'description' => 'Percentage added for each qualifying day. 0.10 is 10%.',
                'type' => 'decimal',
                'group' => 'Night Differential',
            ],
            [
                'key' => 'night_window_start',
                'value' => '22:00',
                'label' => 'Night window starts',
                'description' => 'A shift overlapping this window earns night differential.',
                'type' => 'time',
                'group' => 'Night Differential',
            ],
            [
                'key' => 'night_window_end',
                'value' => '06:00',
                'label' => 'Night window ends',
                'description' => 'End of the night differential window.',
                'type' => 'time',
                'group' => 'Night Differential',
            ],
            [
                'key' => 'leave_conversion_daily_divisor',
                'value' => '22',
                'label' => 'Leave conversion divisor',
                'description' => 'Working days per month used to price an unused leave day. Deliberately not 30 — a leave day is a working day, and using 30 would underpay the conversion.',
                'type' => 'decimal',
                'group' => 'Leave',
            ],
            [
                'key' => 'overbreak_deduction_enabled',
                'value' => '0',
                'label' => 'Deduct for exceeding break time',
                'description' => 'Over-break minutes are always counted and shown. Turn this on to also deduct them from pay.',
                'type' => 'boolean',
                'group' => 'Deductions',
            ],
            [
                'key' => 'undertime_deduction_enabled',
                'value' => '0',
                'label' => 'Deduct for undertime',
                'description' => 'Undertime minutes are always counted and shown. Turn this on to also deduct them from pay.',
                'type' => 'boolean',
                'group' => 'Deductions',
            ],
            [
                'key' => 'clamp_negative_net_pay',
                'value' => '1',
                'label' => 'Never let net pay go below zero',
                'description' => 'Holds a payslip at zero rather than negative when deductions exceed earnings. The shortfall stays on the balance for the next cutoff.',
                'type' => 'boolean',
                'group' => 'Deductions',
            ],
        ];

        foreach ($settings as $setting) {
            PayrollSetting::updateOrCreate(
                ['key' => $setting['key']],
                // value is only set on first insert — reseeding must not undo a
                // figure the company deliberately changed.
                collect($setting)->except('value')->all() + (
                    PayrollSetting::where('key', $setting['key'])->exists() ? [] : ['value' => $setting['value']]
                )
            );
        }
    }
}
