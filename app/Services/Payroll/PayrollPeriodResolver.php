<?php

namespace App\Services\Payroll;

use Illuminate\Support\Carbon;

/**
 * The company's two cutoffs.
 *
 *   26th of the previous month – 10th   →  paid on the 15th
 *   11th – 25th                          →  paid on the 30th
 *
 * The first cutoff spans a month boundary, which is where date handling
 * normally goes wrong, and the 30th does not exist in February. Both are dealt
 * with here so nothing downstream has to think about it.
 *
 * Pure date arithmetic, no database.
 */
class PayrollPeriodResolver
{
    /**
     * The period a pay date belongs to.
     *
     * @return array{cutoff: string, start: Carbon, end: Carbon, pay_date: Carbon}
     */
    public function forPayDate(Carbon|string $payDate): array
    {
        $date = Carbon::parse($payDate)->startOfDay();

        // Anything paid on or before the 15th settles the cutoff that ended on
        // the 10th; later in the month, the one that ended on the 25th.
        return $date->day <= 15
            ? $this->firstCutoffEndingIn($date->year, $date->month)
            : $this->secondCutoffIn($date->year, $date->month);
    }

    /**
     * The period a working date falls inside.
     *
     * Not the same as forPayDate(): the 11th belongs to the cutoff that pays on
     * the 30th, while the pay date closest to it is the 15th, which settles a
     * period that ended the day before.
     *
     * @return array{cutoff: string, start: Carbon, end: Carbon, pay_date: Carbon}
     */
    public function containing(Carbon|string $date): array
    {
        $on = Carbon::parse($date)->startOfDay();

        if ($on->day >= 26) {
            // Runs into next month and is settled there.
            $next = $on->copy()->addMonthNoOverflow();

            return $this->firstCutoffEndingIn($next->year, $next->month);
        }

        return $on->day <= 10
            ? $this->firstCutoffEndingIn($on->year, $on->month)
            : $this->secondCutoffIn($on->year, $on->month);
    }

    /**
     * @return array{cutoff: string, start: Carbon, end: Carbon, pay_date: Carbon}
     */
    public function payDateFor(int $year, int $month, string $cutoff): array
    {
        return $cutoff === 'first'
            ? $this->firstCutoffEndingIn($year, $month)
            : $this->secondCutoffIn($year, $month);
    }

    /** 26th of the previous month through the 10th, paid on the 15th. */
    public function firstCutoffEndingIn(int $year, int $month): array
    {
        $end = Carbon::create($year, $month, 10)->endOfDay();
        $start = $end->copy()->startOfDay()->subMonthNoOverflow()->day(26);

        return [
            'cutoff' => 'first',
            'start' => $start,
            'end' => $end,
            'pay_date' => Carbon::create($year, $month, 15)->startOfDay(),
        ];
    }

    /** 11th through the 25th, paid on the 30th. */
    public function secondCutoffIn(int $year, int $month): array
    {
        return [
            'cutoff' => 'second',
            'start' => Carbon::create($year, $month, 11)->startOfDay(),
            'end' => Carbon::create($year, $month, 25)->endOfDay(),
            'pay_date' => $this->payDayThirty($year, $month),
        ];
    }

    /**
     * The 30th, or the last day of the month when there is no 30th.
     *
     * February is the whole reason this exists — Carbon::create(2026, 2, 30)
     * silently rolls into March, which would pay a February cutoff in March and
     * push it into the wrong 13th-month year.
     */
    public function payDayThirty(int $year, int $month): Carbon
    {
        $lastDay = Carbon::create($year, $month, 1)->endOfMonth()->day;

        return Carbon::create($year, $month, min(30, $lastDay))->startOfDay();
    }

    /** The period immediately following the one containing a date. */
    public function nextPayDateAfter(Carbon|string $date): Carbon
    {
        $from = Carbon::parse($date)->startOfDay();

        $fifteenth = Carbon::create($from->year, $from->month, 15)->startOfDay();

        if ($from->lt($fifteenth)) {
            return $fifteenth;
        }

        $thirtieth = $this->payDayThirty($from->year, $from->month);

        if ($from->lt($thirtieth)) {
            return $thirtieth;
        }

        $next = $from->copy()->addMonthNoOverflow();

        return Carbon::create($next->year, $next->month, 15)->startOfDay();
    }

    /** Calendar days a period covers, both ends included. */
    public function calendarDays(Carbon $start, Carbon $end): int
    {
        return $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1;
    }

    /**
     * Every date in a period, as Y-m-d strings. The aggregator walks these
     * rather than querying per day.
     *
     * @return list<string>
     */
    public function datesIn(Carbon $start, Carbon $end): array
    {
        $dates = [];
        $cursor = $start->copy()->startOfDay();
        $last = $end->copy()->startOfDay();

        while ($cursor->lte($last)) {
            $dates[] = $cursor->toDateString();
            $cursor->addDay();
        }

        return $dates;
    }

    public function label(array $period): string
    {
        return $period['start']->format('M j') . ' – ' . $period['end']->format('M j, Y')
            . ' (paid ' . $period['pay_date']->format('M j') . ')';
    }
}
