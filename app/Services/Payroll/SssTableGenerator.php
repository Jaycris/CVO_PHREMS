<?php

namespace App\Services\Payroll;

use App\Models\SssBracket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the SSS bracket table from the handful of figures a circular actually
 * changes — the two rates, the salary credit range, and where the provident
 * fund starts.
 *
 * SSS publishes a thirty-odd row table, but every row follows the same rule.
 * Regenerating it beats asking anyone to retype thirty rows of pesos, which is
 * thirty chances to mistype one.
 */
class SssTableGenerator
{
    public const STEP = 500;

    /**
     * Replaces the whole table for an effective date.
     *
     * @param  array{employee_rate: float, employer_rate: float, msc_floor: float, msc_ceiling: float, regular_ceiling: float, ec_low: float, ec_high: float, ec_threshold: float}  $p
     */
    public function generate(array $p, Carbon|string $effectiveFrom): int
    {
        $from = Carbon::parse($effectiveFrom)->toDateString();

        abort_if($p['msc_floor'] <= 0, 422, 'The lowest salary credit must be greater than zero.');
        abort_if($p['msc_ceiling'] < $p['msc_floor'], 422, 'The highest salary credit cannot be below the lowest.');
        abort_if($p['employee_rate'] < 0 || $p['employer_rate'] < 0, 422, 'Contribution rates cannot be negative.');

        return DB::transaction(function () use ($p, $from) {
            // Regenerating replaces this effective period outright. Earlier
            // periods are untouched, so historical payslips still reproduce.
            SssBracket::whereDate('effective_from', $from)->delete();

            $rows = [];
            $step = static::STEP;
            $regularCeiling = min($p['regular_ceiling'], $p['msc_ceiling']);

            for ($msc = $p['msc_floor']; $msc <= $p['msc_ceiling']; $msc += $step) {
                $isFirst = $msc == $p['msc_floor'];
                $isLast = ($msc + $step) > $p['msc_ceiling'];

                $regular = min($msc, $regularCeiling);
                $provident = max(0, $msc - $regularCeiling);

                $rows[] = [
                    'salary_from' => $isFirst ? 0 : $msc - ($step / 2),
                    'salary_to' => $isLast ? null : $msc + ($step / 2) - 0.01,
                    'monthly_salary_credit' => $msc,
                    'employee_share' => round($regular * $p['employee_rate'], 2),
                    'employer_share' => round($regular * $p['employer_rate'], 2),
                    'employee_mpf_share' => round($provident * $p['employee_rate'], 2),
                    'employer_mpf_share' => round($provident * $p['employer_rate'], 2),
                    'employee_compensation' => $msc >= $p['ec_threshold'] ? $p['ec_high'] : $p['ec_low'],
                    'effective_from' => $from,
                    'effective_to' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            SssBracket::insert($rows);

            return count($rows);
        });
    }

    /**
     * Reads the parameters back out of a generated table, so the form can show
     * what is currently in force without storing the same figures twice.
     *
     * @return array<string, float>
     */
    public function describe(Collection $brackets): array
    {
        $first = $brackets->sortBy('monthly_salary_credit')->first();
        $last = $brackets->sortByDesc('monthly_salary_credit')->first();

        if (! $first || ! $last) {
            return $this->defaults();
        }

        $floor = (float) $first->monthly_salary_credit;

        // The provident fund starts above the highest credit still paying
        // nothing into it.
        $regularCeiling = (float) ($brackets
            ->filter(fn (SssBracket $b) => (float) $b->employee_mpf_share === 0.0)
            ->sortByDesc('monthly_salary_credit')
            ->first()?->monthly_salary_credit ?? $last->monthly_salary_credit);

        $withEc = $brackets->filter(fn (SssBracket $b) => (float) $b->employee_compensation > (float) $first->employee_compensation)
            ->sortBy('monthly_salary_credit')
            ->first();

        return [
            'employee_rate' => $floor > 0 ? round((float) $first->employee_share / $floor, 4) : 0,
            'employer_rate' => $floor > 0 ? round((float) $first->employer_share / $floor, 4) : 0,
            'msc_floor' => $floor,
            'msc_ceiling' => (float) $last->monthly_salary_credit,
            'regular_ceiling' => $regularCeiling,
            'ec_low' => (float) $first->employee_compensation,
            'ec_high' => (float) ($withEc?->employee_compensation ?? $first->employee_compensation),
            'ec_threshold' => (float) ($withEc?->monthly_salary_credit ?? 0),
        ];
    }

    /** @return array<string, float> */
    public function defaults(): array
    {
        return [
            'employee_rate' => 0.05,
            'employer_rate' => 0.10,
            'msc_floor' => 5000,
            'msc_ceiling' => 35000,
            'regular_ceiling' => 20000,
            'ec_low' => 10,
            'ec_high' => 30,
            'ec_threshold' => 15000,
        ];
    }
}
