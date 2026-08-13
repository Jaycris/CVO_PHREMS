<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\ThirteenthMonthOpeningBalance;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The thirteenth month.
 *
 * One twelfth of the basic pay an employee actually earned during the year —
 * "actually earned" being the point: absences and leave without pay already
 * came off basic_earned when each payslip was worked out, so they reduce this
 * too, exactly as the law intends. Overtime, night differential, allowances and
 * cashed-out leave are not basic pay and are deliberately excluded.
 *
 * The year is counted by pay date rather than by the period worked, so the
 * 26 Dec – 10 Jan cutoff belongs to the year it was paid in. That is the
 * convention the BIR form follows.
 */
class ThirteenthMonthService
{
    /**
     * Basic pay this system recorded for an employee in a year, plus whatever
     * HR entered for the months that predate it.
     *
     * @return array{system: float, opening: float, total: float}
     */
    public function basicEarnedFor(Employee $employee, int $year): array
    {
        $system = (float) Payslip::where('employee_id', $employee->id)
            ->whereHas('payrollRun', fn ($q) => $q
                ->where('run_type', 'regular')
                // Only settled runs count. A draft can still change, and paying
                // a thirteenth month off figures that then move is worse than
                // waiting for the run to be locked.
                ->whereIn('status', ['finalized', 'paid'])
                ->whereYear('pay_date', $year))
            ->sum('basic_earned');

        $opening = (float) ThirteenthMonthOpeningBalance::where('employee_id', $employee->id)
            ->where('for_year', $year)
            ->value('basic_earned');

        return [
            'system' => round($system, 2),
            'opening' => round($opening, 2),
            'total' => round($system + $opening, 2),
        ];
    }

    public function amountFor(Employee $employee, int $year): float
    {
        return round($this->basicEarnedFor($employee, $year)['total'] / 12, 2);
    }

    /**
     * What every eligible employee is owed, for review before anything is paid.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function preview(int $year): Collection
    {
        return Employee::where('include_in_payroll', true)
            ->orderBy('employee_id')
            ->get()
            ->map(function (Employee $employee) use ($year) {
                $earned = $this->basicEarnedFor($employee, $year);

                return [
                    'employee' => $employee,
                    'system' => $earned['system'],
                    'opening' => $earned['opening'],
                    'total' => $earned['total'],
                    'amount' => round($earned['total'] / 12, 2),
                ];
            })
            // Someone who earned nothing this year is owed nothing, and a row
            // of zeroes on the register only invites the question.
            ->filter(fn (array $row) => $row['amount'] > 0)
            ->values();
    }

    /**
     * Creates the thirteenth month run and its payslips.
     *
     * A run of its own rather than a line on a regular payslip: it is paid on
     * its own schedule, reported separately, and taxed differently above the
     * exempt threshold. Folding it into a cutoff would make all three harder.
     */
    public function generate(int $year, ?User $actor = null): PayrollRun
    {
        $rows = $this->preview($year);

        abort_if($rows->isEmpty(), 422, 'No employee has any basic pay recorded for ' . $year . '.');

        return DB::transaction(function () use ($year, $rows, $actor) {
            $run = PayrollRun::firstOrCreate(
                [
                    'run_type' => 'thirteenth_month',
                    'period_start' => "{$year}-01-01",
                    'period_end' => "{$year}-12-31",
                ],
                [
                    'cutoff' => null,
                    'pay_date' => "{$year}-12-15",
                    'status' => 'draft',
                ]
            );

            abort_unless($run->isMutable(), 403, 'The ' . $year . ' thirteenth month run is already locked.');

            if ($run->wasRecentlyCreated) {
                $run->log('opened', 'Thirteenth month for ' . $year);
            }

            $total = 0.0;

            foreach ($rows as $row) {
                $employee = $row['employee'];

                Payslip::updateOrCreate(
                    ['payroll_run_id' => $run->id, 'employee_id' => $employee->id],
                    [
                        'basic_salary' => (float) $employee->basic_salary,
                        // The thirteenth month is not itself basic pay, so
                        // basic_earned stays zero — otherwise next year's
                        // calculation would fold this payment into its own base.
                        'basic_earned' => 0,
                        'basic_pay' => 0,
                        'thirteenth_month_pay' => $row['amount'],
                        'gross_pay' => $row['amount'],
                        'total_deductions' => 0,
                        'net_pay' => $row['amount'],
                        'employee_snapshot' => [
                            'name' => $employee->fullName() ?: $employee->employee_id,
                            'employee_id' => $employee->employee_id,
                            'department' => $employee->department?->name,
                            'position' => $employee->position?->title,
                            'basic_earned_for_year' => $row['total'],
                        ],
                    ]
                );

                $total += $row['amount'];
            }

            // Payslips no longer owed anything — an employee removed from
            // payroll since the last generate — must not linger.
            $run->payslips()
                ->whereNotIn('employee_id', $rows->pluck('employee.id'))
                ->get()
                ->each(fn (Payslip $p) => $p->delete());

            $run->update([
                'status' => 'computed',
                'computed_at' => now(),
                'computed_by_user_id' => $actor?->id,
                'employee_count' => $rows->count(),
                'total_gross' => round($total, 2),
                'total_deductions' => 0,
                'total_net' => round($total, 2),
            ]);

            $run->log('computed', $rows->count() . ' employee(s), ₱' . number_format($total, 2));

            return $run->fresh();
        });
    }
}
