<?php

namespace App\Services\Payroll;

use App\Models\CashAdvance;
use App\Models\Employee;
use App\Models\LeaveConversionPayout;
use App\Models\LeaveCreditTransaction;
use App\Models\OvertimeRequest;
use App\Models\PayrollRun;
use App\Models\PayrollSetting;
use App\Models\Payslip;
use App\Models\PayslipLine;
use App\Models\User;
use App\Services\CashAdvanceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The lifecycle of a payroll run.
 *
 *   draft ──compute──▶ computed ──finalize──▶ finalized ──markPaid──▶ paid
 *              ▲            │
 *              └─recompute──┘
 *
 * Recompute is the normal way to fix a mistake, and it is safe to press twice:
 * everything the system works out for itself is rebuilt, while anything a human
 * typed in survives.
 *
 * Unfinalize exists for the case where a mistake is caught after locking, but
 * it is refused once a run is paid. Money has left the bank by then, and
 * rewriting history to match a correction is worse than carrying the correction
 * forward as an adjustment on the next run.
 */
class PayrollService
{
    public function __construct(
        protected PayrollPeriodResolver $periods = new PayrollPeriodResolver(),
        protected AttendanceAggregator $aggregator = new AttendanceAggregator(),
        protected CashAdvanceService $cashAdvances = new CashAdvanceService(),
    ) {}

    /**
     * Opens the draft for a cutoff. Idempotent — the unique key on the period
     * means a second call returns the existing run rather than creating a
     * duplicate that could later be finalised alongside the first.
     */
    public function openRun(int $year, int $month, string $cutoff, string $runType = 'regular'): PayrollRun
    {
        $period = $this->periods->payDateFor($year, $month, $cutoff);

        $run = PayrollRun::firstOrCreate(
            [
                'run_type' => $runType,
                'period_start' => $period['start']->toDateString(),
                'period_end' => $period['end']->toDateString(),
            ],
            [
                'cutoff' => $cutoff,
                'pay_date' => $period['pay_date']->toDateString(),
                'status' => 'draft',
            ]
        );

        if ($run->wasRecentlyCreated) {
            $run->log('opened', 'Draft opened for ' . $run->periodLabel());
        }

        return $run;
    }

    /**
     * Blocking problems and warnings, before anyone is allowed to compute.
     *
     * Blocking issues are the ones that would silently produce a wrong number:
     * a missing schedule invents an absence, a day punched in but never out
     * cannot be measured, and a zero salary pays nothing at all.
     *
     * @return array{blocking: list<string>, warnings: list<string>, employees: int}
     */
    public function preflight(PayrollRun $run): array
    {
        $employees = $this->eligibleEmployees($run);
        $blocking = [];
        $warnings = [];

        if ($employees->isEmpty()) {
            $blocking[] = 'No employees fall in this period.';

            return ['blocking' => $blocking, 'warnings' => $warnings, 'employees' => 0];
        }

        foreach ($employees as $employee) {
            if ((float) $employee->basic_salary <= 0) {
                $blocking[] = $this->name($employee) . ' has no basic salary set.';
            }

            if (! $employee->user) {
                $warnings[] = $this->name($employee) . ' has no login, so cannot be sent a payslip.';
            }
        }

        $counters = $this->aggregator->aggregate($employees, $run->period_start, $run->period_end);

        foreach ($employees as $employee) {
            $c = $counters[$employee->id] ?? [];

            // Summarised per employee rather than per day. A missing schedule
            // is missing for the whole period, so listing every date turns one
            // problem into sixteen lines and buries the rest of the panel.
            if ($unscheduled = $c['unscheduled_days'] ?? []) {
                $blocking[] = $this->name($employee) . ' has no work schedule for '
                    . $this->dateRange($unscheduled) . '. Assign one on their employee page.';
            }

            if ($unclosed = $c['unclosed_days'] ?? []) {
                $blocking[] = $this->name($employee) . ' clocked in but never out on '
                    . $this->dateRange($unclosed) . '.';
            }

            // Not blocking — someone genuinely on leave all period is legitimate
            // — but a whole cutoff of absences usually means attendance was
            // never recorded, and it would wipe out their pay without comment.
            if (($c['days_expected'] ?? 0) > 0 && ($c['days_present'] ?? 0) === 0 && ($c['days_on_paid_leave'] ?? 0) === 0) {
                $warnings[] = $this->name($employee) . ' has no attendance at all this period, so will be paid as absent for '
                    . $c['days_expected'] . ' day(s).';
            }
        }

        $pendingOvertime = OvertimeRequest::whereIn('employee_id', $employees->pluck('id'))
            ->where('status', 'pending_manager')
            ->whereDate('work_date', '>=', $run->period_start->toDateString())
            ->whereDate('work_date', '<=', $run->period_end->toDateString())
            ->count();

        if ($pendingOvertime > 0) {
            $warnings[] = $pendingOvertime . ' overtime request(s) in this period are still awaiting approval and will not be paid.';
        }

        return [
            'blocking' => array_values(array_unique($blocking)),
            'warnings' => array_values(array_unique($warnings)),
            'employees' => $employees->count(),
        ];
    }

    /**
     * Works out every payslip in the run.
     *
     * Safe to run repeatedly. The order matters: side effects from the previous
     * attempt are released first, so a recompute neither double-charges a cash
     * advance nor double-consumes overtime.
     */
    public function compute(PayrollRun $run, ?User $actor = null): PayrollRun
    {
        abort_unless($run->isMutable(), 403, 'This payroll run is locked and cannot be recomputed.');

        $preflight = $this->preflight($run);

        // abort_if evaluates its message eagerly, so the first blocking issue
        // has to be read only once we know there is one.
        if ($preflight['blocking'] !== []) {
            abort(422, 'This payroll cannot be computed yet: ' . $preflight['blocking'][0]);
        }

        return DB::transaction(function () use ($run, $actor) {
            $run = PayrollRun::lockForUpdate()->find($run->id);

            $this->rollbackSideEffects($run);

            $employees = $this->eligibleEmployees($run);
            $counters = $this->aggregator->aggregate($employees, $run->period_start, $run->period_end);

            $statutory = (new StatutoryDeductionCalculator)->preload($run->pay_date);
            $calculator = new PayslipCalculator($statutory);

            $advances = $this->cashAdvances->activeForPeriod($run->period_end)->keyBy('employee_id');
            $clampNegative = PayrollSetting::flag('clamp_negative_net_pay', true);

            $totals = ['gross' => 0.0, 'deductions' => 0.0, 'net' => 0.0];

            foreach ($employees as $employee) {
                $figures = $calculator->calculate($employee, $counters[$employee->id] ?? [], $run->cutoff ?? 'second');

                // updateOrCreate rather than delete-and-recreate: the payslip id
                // has to survive, because adjustments hang off it.
                $payslip = Payslip::updateOrCreate(
                    ['payroll_run_id' => $run->id, 'employee_id' => $employee->id],
                    $this->payslipAttributes($employee, $counters[$employee->id] ?? [], $figures)
                );

                $this->applyLeaveConversion($payslip, $employee, $run);
                $this->applyCashAdvance($payslip, $advances->get($employee->id), $run, $clampNegative);
                $this->applyAdjustments($payslip);
                $this->writeLines($payslip);

                $payslip->refresh();

                $totals['gross'] += (float) $payslip->gross_pay;
                $totals['deductions'] += (float) $payslip->total_deductions;
                $totals['net'] += (float) $payslip->net_pay;
            }

            // Overtime is stamped as consumed so it cannot be paid again on a
            // later run, and the stamp is what rollbackSideEffects clears.
            OvertimeRequest::whereIn('employee_id', $employees->pluck('id'))
                ->where('status', 'approved')
                ->whereNull('consumed_payroll_run_id')
                ->whereDate('work_date', '>=', $run->period_start->toDateString())
            ->whereDate('work_date', '<=', $run->period_end->toDateString())
                ->update(['consumed_payroll_run_id' => $run->id]);

            $run->update([
                'status' => 'computed',
                'computed_at' => now(),
                'computed_by_user_id' => $actor?->id,
                'employee_count' => $employees->count(),
                'total_gross' => round($totals['gross'], 2),
                'total_deductions' => round($totals['deductions'], 2),
                'total_net' => round($totals['net'], 2),
            ]);

            $run->log('computed', $employees->count() . ' payslips, net ₱' . number_format($totals['net'], 2));

            return $run->fresh();
        });
    }

    /** Locks the figures. Nothing may change afterwards without unfinalizing. */
    public function finalize(PayrollRun $run, ?User $actor = null): PayrollRun
    {
        abort_unless($run->status === 'computed', 403, 'Only a computed payroll run can be finalized.');
        abort_if($run->payslips()->count() === 0, 422, 'This run has no payslips to finalize.');

        $run->update([
            'status' => 'finalized',
            'finalized_at' => now(),
            'finalized_by_user_id' => $actor?->id,
        ]);

        $run->log('finalized', 'Figures locked.');

        return $run->fresh();
    }

    /**
     * Reopens a finalised run.
     *
     * Refused once paid. After money has left the bank the correct fix is an
     * adjustment on the next run, which leaves a trail; rewriting a paid
     * payslip leaves the bank record and the payslip disagreeing with nothing
     * to explain why.
     */
    public function unfinalize(PayrollRun $run, User $actor, string $reason): PayrollRun
    {
        abort_unless($run->status === 'finalized', 403, 'Only a finalized run can be reopened.');
        abort_unless($actor->can('payroll.runs.unlock'), 403, 'You cannot reopen a finalized payroll run.');
        abort_if(trim($reason) === '', 422, 'A reason is required to reopen a finalized run.');

        return DB::transaction(function () use ($run, $reason) {
            // Withdraw whatever was already released. Without this an employee
            // keeps seeing a payslip that is now being changed, and re-sending
            // after the correction would skip them as already notified — so
            // they would never see the corrected figure at all.
            $withdrawn = $run->payslips()->whereNotNull('notified_at')->count();

            if ($withdrawn > 0) {
                $run->payslips()->newQuery()
                    ->where('payroll_run_id', $run->id)
                    ->update(['notified_at' => null]);
            }

            $run->update([
                'status' => 'computed',
                'finalized_at' => null,
                'finalized_by_user_id' => null,
            ]);

            $run->log('unfinalized', $reason . ($withdrawn > 0
                ? ' — ' . $withdrawn . ' released payslip(s) withdrawn from employees'
                : ''));

            return $run->fresh();
        });
    }

    public function markPaid(PayrollRun $run, ?User $actor = null): PayrollRun
    {
        abort_unless($run->status === 'finalized', 403, 'Only a finalized payroll run can be marked paid.');

        $run->update([
            'status' => 'paid',
            'paid_at' => now(),
            'paid_by_user_id' => $actor?->id,
        ]);

        $run->log('marked_paid', 'Net ₱' . number_format((float) $run->total_net, 2) . ' released.');

        return $run->fresh();
    }

    /**
     * Discards a run entirely.
     *
     * Hard delete rather than a cancelled status, because the unique key on the
     * period would otherwise stop a corrected run being opened for the same
     * cutoff. Only ever available before finalizing.
     */
    public function cancel(PayrollRun $run): void
    {
        abort_unless($run->isMutable(), 403, 'A finalized or paid payroll run cannot be cancelled.');

        DB::transaction(function () use ($run) {
            $this->rollbackSideEffects($run);
            $run->delete();
        });
    }

    /**
     * Releases everything a previous compute did outside the payslip's own
     * columns, so recomputing starts from a clean slate.
     *
     * Adjustments are deliberately left alone — they are the human's input, not
     * the system's.
     */
    protected function rollbackSideEffects(PayrollRun $run): void
    {
        $this->cashAdvances->reverseForRun($run->id);

        OvertimeRequest::where('consumed_payroll_run_id', $run->id)
            ->update(['consumed_payroll_run_id' => null]);

        // Releases the leave conversions this run paid, so recomputing prices
        // them again rather than skipping them as already settled.
        LeaveConversionPayout::where('payroll_run_id', $run->id)->delete();

        PayslipLine::whereIn('payslip_id', $run->payslips()->select('id'))->delete();
    }

    /** @return Collection<int, Employee> */
    protected function eligibleEmployees(PayrollRun $run): Collection
    {
        return Employee::forPayroll($run->period_start, $run->period_end)
            ->with('user', 'department', 'position')
            ->orderBy('employee_id')
            ->get();
    }

    /** @return array<string, mixed> */
    protected function payslipAttributes(Employee $employee, array $counters, array $figures): array
    {
        return [
            'basic_salary' => (float) $employee->basic_salary,
            'daily_rate' => $figures['daily_rate'],
            'hourly_rate' => $figures['hourly_rate'],
            'minute_rate' => $figures['minute_rate'],

            'days_expected' => $counters['days_expected'] ?? 0,
            'days_present' => $counters['days_present'] ?? 0,
            'days_absent' => $counters['days_absent'] ?? 0,
            'days_paid_leave' => $counters['days_on_paid_leave'] ?? 0,
            'days_lwop' => $counters['days_lwop'] ?? 0,
            'days_rest' => $counters['days_rest'] ?? 0,
            'night_diff_days' => $counters['night_diff_days'] ?? 0,
            'late_minutes' => $counters['late_minutes'] ?? 0,
            'undertime_minutes' => $counters['undertime_minutes'] ?? 0,
            'over_break_minutes' => $counters['over_break_minutes'] ?? 0,
            'overtime_hours' => $counters['overtime_hours'] ?? 0,

            'basic_pay' => $figures['basic_pay'],
            'absence_deduction' => $figures['absence_deduction'],
            'basic_earned' => $figures['basic_earned'],
            'overtime_pay' => $figures['overtime_pay'],
            'night_differential_pay' => $figures['night_differential_pay'],
            'allowance' => $figures['allowance'],
            'gross_pay' => $figures['gross_pay'],

            'late_deduction' => $figures['late_deduction'],
            'undertime_deduction' => $figures['undertime_deduction'],
            'over_break_deduction' => $figures['over_break_deduction'],
            'sss_employee' => $figures['sss_employee'],
            'philhealth_employee' => $figures['philhealth_employee'],
            'pagibig_employee' => $figures['pagibig_employee'],
            'total_contributions' => $figures['total_contributions'],
            'taxable_income' => $figures['taxable_income'],
            'withholding_tax' => $figures['withholding_tax'],
            'total_deductions' => $figures['total_deductions'],
            'net_pay' => $figures['net_pay'],

            'sss_employer' => $figures['sss_employer'],
            'sss_employee_compensation' => $figures['sss_employee_compensation'],
            'philhealth_employer' => $figures['philhealth_employer'],
            'pagibig_employer' => $figures['pagibig_employer'],

            // Frozen now so a payslip reprinted years later still shows the
            // person as they were on it.
            'employee_snapshot' => [
                'name' => $employee->fullName() ?: $employee->employee_id,
                'employee_id' => $employee->employee_id,
                'department' => $employee->department?->name,
                'position' => $employee->position?->title,
                'tin' => $employee->tin_number,
                'sss' => $employee->sss_number,
                'philhealth' => $employee->philhealth_number,
                'pagibig' => $employee->pagibig_number,
            ],

            // Reset each compute; filled in by the steps that follow.
            'cash_advance_deduction' => 0,
            'leave_conversion_pay' => 0,
            'adjustments_earning' => 0,
            'adjustments_deduction' => 0,
        ];
    }

    /**
     * Pays out unused leave the annual reset converted to cash.
     *
     * The reset takes the days off the balance; this prices them and puts them
     * on a payslip. A day is priced over working days rather than calendar days
     * — a leave day is a working day, and using the calendar divisor would
     * underpay the conversion by roughly a quarter.
     *
     * The unique key on the transaction is what makes this safe: a recompute or
     * a reopened run cannot pay the same days twice, and unlike most payroll
     * mistakes that one is invisible because the balance is already zero.
     */
    protected function applyLeaveConversion(Payslip $payslip, Employee $employee, PayrollRun $run): void
    {
        $pending = LeaveCreditTransaction::where('employee_id', $employee->id)
            ->unpaidConversions()
            ->get();

        if ($pending->isEmpty()) {
            return;
        }

        $divisor = PayrollSetting::number('leave_conversion_daily_divisor', 22);

        if ($divisor <= 0) {
            return;
        }

        $rate = (float) $employee->basic_salary / $divisor;
        $total = 0.0;

        foreach ($pending as $transaction) {
            // The reset writes the days as a negative, being a deduction from
            // the balance; what is owed is the size of it.
            $days = abs((float) $transaction->amount);
            $amount = round($rate * $days, 2);

            LeaveConversionPayout::create([
                'leave_credit_transaction_id' => $transaction->id,
                'employee_id' => $employee->id,
                'payroll_run_id' => $run->id,
                'payslip_id' => $payslip->id,
                'days' => $days,
                'daily_rate' => round($rate, 4),
                'amount' => $amount,
                'for_year' => $transaction->transaction_date->year,
            ]);

            $total += $amount;
        }

        if ($total <= 0) {
            return;
        }

        $payslip->update([
            'leave_conversion_pay' => round($total, 2),
            'gross_pay' => round((float) $payslip->gross_pay + $total, 2),
            'net_pay' => round((float) $payslip->net_pay + $total, 2),
        ]);
    }

    /**
     * Takes this cutoff's instalment, shrunk to whatever the payslip can bear.
     * The shortfall simply stays on the balance rather than driving net pay
     * negative.
     */
    protected function applyCashAdvance(Payslip $payslip, ?CashAdvance $advance, PayrollRun $run, bool $clampNegative): void
    {
        if (! $advance) {
            return;
        }

        $ceiling = $clampNegative ? (float) $payslip->net_pay : PHP_FLOAT_MAX;

        $payment = $this->cashAdvances->applyToPayslip(
            $advance,
            $run->id,
            $payslip->id,
            $run->pay_date,
            $ceiling,
        );

        if (! $payment) {
            return;
        }

        $amount = (float) $payment->amount;

        $payslip->update([
            'cash_advance_deduction' => $amount,
            'total_deductions' => round((float) $payslip->total_deductions + $amount, 2),
            'net_pay' => round((float) $payslip->net_pay - $amount, 2),
        ]);
    }

    /** Folds in whatever a human added by hand. */
    protected function applyAdjustments(Payslip $payslip): void
    {
        $adjustments = $payslip->adjustments()->get();

        if ($adjustments->isEmpty()) {
            return;
        }

        $earnings = round((float) $adjustments->where('type', 'earning')->sum('amount'), 2);
        $deductions = round((float) $adjustments->where('type', 'deduction')->sum('amount'), 2);

        $payslip->update([
            'adjustments_earning' => $earnings,
            'adjustments_deduction' => $deductions,
            'gross_pay' => round((float) $payslip->gross_pay + $earnings, 2),
            'total_deductions' => round((float) $payslip->total_deductions + $deductions, 2),
            'net_pay' => round((float) $payslip->net_pay + $earnings - $deductions, 2),
        ]);
    }

    /** Rebuilds the printed lines from the payslip's own figures. */
    protected function writeLines(Payslip $payslip): void
    {
        $payslip->refresh();
        $payslip->lines()->delete();

        $rows = [];
        $order = 0;

        $add = function (string $section, string $label, float $amount, ?string $detail = null) use (&$rows, &$order, $payslip) {
            if (abs($amount) < 0.005) {
                return;
            }

            $rows[] = [
                'payslip_id' => $payslip->id,
                'section' => $section,
                'label' => $label,
                'detail' => $detail,
                'amount' => round($amount, 2),
                'sort_order' => $order++,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        };

        $add('earning', 'Basic pay', (float) $payslip->basic_pay, 'Half of monthly salary');
        $add('earning', 'Absences', -(float) $payslip->absence_deduction,
            ($payslip->days_absent + $payslip->days_lwop) . ' day(s)');
        $add('earning', 'Overtime', (float) $payslip->overtime_pay, $payslip->overtime_hours . ' hour(s)');
        $add('earning', 'Night differential', (float) $payslip->night_differential_pay, $payslip->night_diff_days . ' day(s)');
        $add('earning', 'Allowance', (float) $payslip->allowance);
        $add('earning', 'Unused leave paid out', (float) $payslip->leave_conversion_pay,
            $payslip->leaveConversionDays() . ' day(s)');

        foreach ($payslip->adjustments()->where('type', 'earning')->get() as $adjustment) {
            $add('earning', $adjustment->label, (float) $adjustment->amount, $adjustment->note);
        }

        $add('deduction', 'Late', (float) $payslip->late_deduction, $payslip->late_minutes . ' minute(s)');
        $add('deduction', 'Undertime', (float) $payslip->undertime_deduction, $payslip->undertime_minutes . ' minute(s)');
        $add('deduction', 'Over break', (float) $payslip->over_break_deduction, $payslip->over_break_minutes . ' minute(s)');
        $add('deduction', 'SSS', (float) $payslip->sss_employee);
        $add('deduction', 'PhilHealth', (float) $payslip->philhealth_employee);
        $add('deduction', 'Pag-IBIG', (float) $payslip->pagibig_employee);
        $add('deduction', 'Withholding tax', (float) $payslip->withholding_tax);
        $add('deduction', 'Cash advance', (float) $payslip->cash_advance_deduction);

        foreach ($payslip->adjustments()->where('type', 'deduction')->get() as $adjustment) {
            $add('deduction', $adjustment->label, (float) $adjustment->amount, $adjustment->note);
        }

        $add('employer', 'SSS', (float) $payslip->sss_employer);
        $add('employer', 'SSS work injury insurance', (float) $payslip->sss_employee_compensation);
        $add('employer', 'PhilHealth', (float) $payslip->philhealth_employer);
        $add('employer', 'Pag-IBIG', (float) $payslip->pagibig_employer);

        if ($rows) {
            PayslipLine::insert($rows);
        }
    }

    protected function name(Employee $employee): string
    {
        return $employee->fullName() ?: $employee->employee_id;
    }

    /**
     * A run of dates as a phrase a person can read: one date, two dates joined,
     * or a span with the count.
     *
     * @param  list<string>  $dates
     */
    protected function dateRange(array $dates): string
    {
        $sorted = collect($dates)->sort()->values();
        $count = $sorted->count();

        $first = Carbon::parse($sorted->first())->format('M j');

        if ($count === 1) {
            return $first;
        }

        $last = Carbon::parse($sorted->last())->format('M j');

        if ($count === 2) {
            return $first . ' and ' . $last;
        }

        return $first . ' – ' . $last . ' (' . $count . ' days)';
    }
}
