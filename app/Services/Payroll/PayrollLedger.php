<?php

namespace App\Services\Payroll;

use App\Models\CashCategory;
use App\Models\CashEntry;
use App\Models\FinalPay;
use App\Models\PayrollRun;

/**
 * Payroll money appearing in Money In & Out.
 *
 * Salaries are the largest thing the company spends, and until now the ledger
 * never saw them — it showed rent and electricity while a six-figure payroll
 * went out unrecorded, so the balance was never the business's balance.
 *
 * Written when the money actually moves: a run marked paid, a settlement
 * released. Not when it is computed, because computed figures change.
 */
class PayrollLedger
{
    public const RUN_SOURCE = 'payroll_run';

    public const FINAL_PAY_SOURCE = 'final_pay';

    public const RUN_CATEGORY = 'Payroll';

    public const FINAL_PAY_CATEGORY = 'Final Pay';

    /** Every source this class writes, so the ledger knows what it may not edit. */
    public const SOURCES = [self::RUN_SOURCE, self::FINAL_PAY_SOURCE];

    public function recordRun(PayrollRun $run): CashEntry
    {
        return $this->write(
            self::RUN_SOURCE,
            $run->id,
            self::RUN_CATEGORY,
            $run->pay_date?->toDateString() ?? now()->toDateString(),
            ($run->run_type === 'thirteenth_month' ? '13th month pay — ' : 'Payroll — ')
                . $run->periodLabel(),
            (float) $run->total_net,
            $run->employee_count . ' employee(s)',
        );
    }

    public function recordFinalPay(FinalPay $finalPay): CashEntry
    {
        $employee = $finalPay->employee;
        $name = $employee?->fullName() ?: ($employee?->employee_id ?? 'Unknown employee');

        return $this->write(
            self::FINAL_PAY_SOURCE,
            $finalPay->id,
            self::FINAL_PAY_CATEGORY,
            $finalPay->released_on?->toDateString() ?? now()->toDateString(),
            'Final pay — ' . $name . ' (' . ($employee?->employee_id ?? '?') . ')',
            (float) $finalPay->net_amount,
            'Last day ' . $finalPay->separation_date->format('M j, Y'),
        );
    }

    /**
     * Written once per source record.
     *
     * updateOrCreate rather than create: marking a run paid twice, or a
     * settlement corrected and released again, must leave one entry rather
     * than two claiming the same money left the bank.
     */
    protected function write(
        string $source,
        int $sourceId,
        string $category,
        string $date,
        string $description,
        float $amount,
        ?string $note = null,
    ): CashEntry {
        return CashEntry::updateOrCreate(
            ['source_type' => $source, 'source_id' => $sourceId],
            [
                'entry_date' => $date,
                'direction' => CashEntry::OUT,
                'cash_category_id' => $this->category($category)->id,
                'description' => $description,
                'amount' => round($amount, 2),
                'note' => $note,
                'recorded_by_user_id' => auth()->id(),
            ],
        );
    }

    /** Created the first time it is needed, so a fresh install needs no seeding. */
    protected function category(string $name): CashCategory
    {
        return CashCategory::firstOrCreate(
            ['name' => $name, 'direction' => CashEntry::OUT],
            ['sort_order' => 99, 'is_active' => true],
        );
    }
}
