<?php

namespace App\Services;

use App\Models\CashEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The arithmetic behind the money record.
 *
 * Kept out of the page so the screen, the CSV and anything reporting on this
 * later all add up the same way. Two places that each total a column is two
 * places that can disagree, and the one people notice is whichever they did
 * not check.
 */
class CashLedgerService
{
    /**
     * In, out and net for a period.
     *
     * One query, not three. Totals are summed in the database rather than by
     * loading every row, because this table only grows.
     *
     * @return array{in: float, out: float, net: float, count: int}
     */
    public function totals(Carbon $start, Carbon $end): array
    {
        $row = CashEntry::between($start, $end)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN cash_entries.direction = 'in'  THEN cash_entries.amount ELSE 0 END), 0) as total_in,
                COALESCE(SUM(CASE WHEN cash_entries.direction = 'out' THEN cash_entries.amount ELSE 0 END), 0) as total_out,
                COUNT(*) as entry_count
            ")
            ->first();

        $in = round((float) $row->total_in, 2);
        $out = round((float) $row->total_out, 2);

        return [
            'in' => $in,
            'out' => $out,
            'net' => round($in - $out, 2),
            'count' => (int) $row->entry_count,
        ];
    }

    /** The same figures for everything ever recorded. */
    public function totalsToDate(): array
    {
        return $this->totals(
            Carbon::create(1970, 1, 1),
            Carbon::now()->addCentury(),
        );
    }

    /**
     * What the money went on, biggest first.
     *
     * This is the "list of expenses" question. Uncategorised entries are kept
     * and shown as such rather than dropped — money that moved without being
     * filed still moved, and hiding it makes the total wrong.
     *
     * @return Collection<int, object{name: string, total: float, share: float}>
     */
    public function byCategory(Carbon $start, Carbon $end, string $direction): Collection
    {
        $rows = CashEntry::between($start, $end)
            ->direction($direction)
            ->leftJoin('cash_categories', 'cash_entries.cash_category_id', '=', 'cash_categories.id')
            ->selectRaw('cash_categories.name as name, SUM(cash_entries.amount) as total')
            ->groupBy('cash_categories.name')
            ->orderByDesc('total')
            ->get();

        $sum = (float) $rows->sum('total');

        return $rows->map(fn ($row) => (object) [
            'name' => $row->name ?? 'Not categorised',
            'total' => round((float) $row->total, 2),
            'share' => $sum > 0 ? round(((float) $row->total / $sum) * 100, 1) : 0.0,
        ]);
    }

    /**
     * Month-by-month in, out and net, oldest first.
     *
     * Built from the months that hold entries rather than a fixed range, so a
     * company three weeks into recording sees three weeks, not eleven empty
     * months making it look like it lost money.
     *
     * @return list<array{label: string, month: string, in: float, out: float, net: float}>
     */
    public function monthlyTrend(int $limit = 12): array
    {
        $months = array_slice(CashEntry::months(), 0, $limit);
        sort($months);

        return array_map(function (string $month) {
            [$year, $monthNumber] = array_map('intval', explode('-', $month));
            $start = Carbon::create($year, $monthNumber, 1)->startOfMonth();
            $totals = $this->totals($start, $start->copy()->endOfMonth());

            return [
                'label' => $start->format('M Y'),
                'month' => $month,
                'in' => $totals['in'],
                'out' => $totals['out'],
                'net' => $totals['net'],
            ];
        }, $months);
    }
}
