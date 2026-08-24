<?php

namespace App\Services\Commission;

use App\Models\CommissionRun;
use App\Models\CommissionSlip;
use App\Models\Employee;
use App\Models\User;
use App\Services\Crm\CommissionSlipService;
use App\Services\Crm\CrmUnavailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A month's commissions, from opening a run to sending the slips.
 *
 * Mirrors payroll deliberately: draft → computed → finalized → sent, with the
 * same rule that nothing reaches an agent until a person has locked the
 * figures. HR opens a run, presses Compute to read the CRM, checks what came
 * back, finalizes, and only then sends.
 *
 * Computing is the only step that talks to the CRM. Everything after it works
 * off what was written down, so an agent's slip cannot change under them
 * because someone edited a sale in the CRM afterwards.
 */
class CommissionRunService
{
    public function __construct(
        protected CommissionSlipService $crm,
    ) {}

    /**
     * Opens a run over any period.
     *
     * A month, a payroll cutoff, a fortnight — the run does not care which, and
     * deliberately so. Where the split falls varies between agents, so no rule
     * about it belongs in here.
     *
     * The agents are pre-selected from their commission frequency, but that is
     * only a starting point: whoever runs it can add and remove people before
     * computing.
     */
    /**
     * @param  list<int>|null  $agentIds  Explicit selection, or null to take the
     *                                    default for this run type.
     */
    public function openRun(
        Carbon|string $start,
        Carbon|string $end,
        string $type = 'monthly',
        ?User $actor = null,
        ?string $label = null,
        ?array $agentIds = null,
    ): CommissionRun {
        $start = Carbon::parse($start)->startOfDay();
        $end = Carbon::parse($end)->startOfDay();

        abort_if($end->lt($start), 422, 'The end date is before the start date.');

        abort_if(
            $start->gt(Carbon::now('Asia/Manila')->endOfDay()),
            422,
            'That period has not started yet.'
        );

        abort_unless(
            in_array($type, ['monthly', 'biweekly', 'custom'], true),
            422,
            'Unknown run type.'
        );

        $existing = CommissionRun::where('run_type', $type)->forPeriod($start, $end)->first();

        if ($existing) {
            return $existing;
        }

        // Resolved before the run is created, not after. Aborting once the row
        // exists leaves an empty run behind that nobody asked for and that then
        // holds the period against a corrected attempt.
        $chosen = $agentIds === null
            ? $this->defaultAgentsFor($type)->pluck('id')
            : Employee::whereIn('id', $agentIds)->pluck('id');

        abort_if($chosen->isEmpty(), 422, 'Pick at least one agent for this run.');

        return DB::transaction(function () use ($type, $start, $end, $label, $chosen) {
            $run = CommissionRun::create([
                'run_type' => $type,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'label' => $label,
                'status' => 'draft',
            ]);

            $run->agents()->sync($chosen);

            $run->log('opened', 'Run opened for ' . $run->periodLabel()
                . ' (' . $run->typeLabel() . '), ' . $chosen->count() . ' agent(s) selected');

            return $run;
        });
    }

    /**
     * Replaces who a run covers.
     *
     * Only before it is computed. Afterwards the slips are the record, and
     * quietly dropping someone whose figures are already written down would
     * leave a run whose totals no longer match its rows.
     *
     * @param  list<int>  $employeeIds
     */
    public function setAgents(CommissionRun $run, array $employeeIds): void
    {
        abort_unless($run->isMutable(), 403, 'This run is locked and its agents cannot be changed.');

        $ids = Employee::whereIn('id', $employeeIds)->pluck('id');

        abort_if($ids->isEmpty(), 422, 'Pick at least one agent.');

        $run->agents()->sync($ids);

        // Slips belonging to agents no longer in the run would otherwise linger
        // with figures nobody asked for.
        $run->slips()->whereNotIn('employee_id', $ids)->delete();

        $run->log('agents_changed', $ids->count() . ' agent(s) selected');
    }

    /**
     * Everyone whose commission frequency matches this run type.
     *
     * A convenience, not a rule — see the pivot table's migration for why the
     * last word is a person's.
     *
     * @return Collection<int, Employee>
     */
    public function suggestedAgentsFor(string $type): Collection
    {
        $frequency = $type === 'biweekly' ? 'biweekly' : 'monthly';

        return Employee::whereNull('separation_date')
            ->where('commission_frequency', $frequency)
            ->orderBy('employee_id')
            ->get();
    }

    /**
     * Who a new run covers when nobody has said otherwise.
     *
     * Everyone matching the run type's frequency, falling back to the whole
     * active roster when nobody has been given a frequency yet. A brand-new
     * install would otherwise open every run with nobody in it, which reads as
     * broken rather than as a setting waiting to be filled in.
     *
     * @return Collection<int, Employee>
     */
    public function defaultAgentsFor(string $type): Collection
    {
        $matching = $this->suggestedAgentsFor($type);

        return $matching->isNotEmpty() ? $matching : $this->selectableAgents();
    }

    /**
     * Reads the CRM for every agent and writes the figures down.
     *
     * One agent failing does not stop the rest. The reason is kept on that
     * agent's own slip, so a run of ninety-nine good slips is not held up by a
     * hundredth whose CRM user is missing its HRIS Employee ID — and the person
     * checking can see exactly which one and why.
     *
     * Safe to run again: slips are updated in place, so a recompute picks up a
     * correction in the CRM without duplicating anything.
     */
    public function compute(CommissionRun $run, ?User $actor = null): CommissionRun
    {
        abort_unless($run->isMutable(), 403, 'This commission run is locked and cannot be recomputed.');
        abort_unless($this->crm->isConfigured(), 422, 'The CRM connection has not been set up yet.');

        $employees = $run->agents()->with(['department', 'position'])->get();

        abort_if(
            $employees->isEmpty(),
            422,
            'No agents are selected for this run. Choose who it covers first.'
        );

        /*
         * A slip already sent is withdrawn before it is recomputed.
         *
         * The notifier only picks up slips with no notified_at, so leaving the
         * stamp in place would mean the corrected figures are never sent: the
         * agent keeps the old slip, Send reports nothing to do, and nobody finds
         * out until they query the amount. Withdrawing costs them sight of it
         * for as long as it takes to send again, which is the right way round.
         */
        $withdrawn = $run->slips()->whereNotNull('notified_at')->count();

        if ($withdrawn > 0) {
            $run->slips()->newQuery()
                ->where('commission_run_id', $run->id)
                ->update(['notified_at' => null]);

            $run->log('slips_withdrawn', $withdrawn . ' sent slip(s) withdrawn for recomputing');
        }

        $start = $run->period_start;
        $end = $run->period_end;
        $totals = ['usd' => 0.0, 'php' => 0.0, 'hold' => 0.0, 'net' => 0.0];
        $failed = 0;

        foreach ($employees as $employee) {
            // Outside the transaction on purpose — an HTTP call held inside one
            // keeps a database lock open for as long as the CRM takes to answer.
            $this->crm->forgetPeriod($employee, $start, $end);

            try {
                $slip = $this->crm->forPeriod($employee, $start, $end);
                $error = null;
            } catch (CrmUnavailable $e) {
                $slip = null;
                $error = $e->getMessage();
                $failed++;
            }

            // The CRM has just told us which plan this agent is on and what
            // they are measured against. Copying it onto the employee record
            // here is what keeps the Payroll Details tab honest — otherwise it
            // shows whatever HR last typed, however long ago that was.
            if ($slip) {
                app(CommissionProfileMirror::class)->apply($employee, $slip);
            }

            DB::transaction(function () use ($run, $employee, $slip, $error, &$totals) {
                $record = CommissionSlip::updateOrCreate(
                    ['commission_run_id' => $run->id, 'employee_id' => $employee->id],
                    $this->slipAttributes($employee, $slip, $error),
                );

                // Rebuilt rather than appended, so a recompute after a
                // correction leaves one statement and not two.
                $record->lines()->delete();

                if ($slip) {
                    foreach ($slip->transactions as $index => $row) {
                        $record->lines()->create([
                            'sort_order' => $index,
                            'sold_date' => $row->soldDate,
                            'brand' => $row->brand,
                            'client' => $row->client,
                            'book_title' => $row->bookTitle,
                            'service' => $row->service,
                            'payment_method' => $row->paymentMethod,
                            'sale_amount' => $row->saleAmount,
                            'service_amount' => $row->serviceAmount,
                            'markup_amount' => $row->markupAmount,
                            'threshold_applied' => $row->thresholdApplied,
                            'service_commission' => $row->serviceCommission,
                            'markup_commission' => $row->markupCommission,
                            'usd_total' => $row->usdTotal,
                            'php_total' => $row->phpTotal,
                            'card_hold_amount' => $row->cardHoldAmount,
                            'net_commission' => $row->netCommission,
                        ]);
                    }

                    $totals['usd'] += (float) ($slip->usdTotal ?? 0);
                    $totals['php'] += (float) ($slip->phpTotal ?? 0);
                    $totals['hold'] += (float) ($slip->cardHoldAmount ?? 0);
                    $totals['net'] += (float) ($slip->netCommission ?? 0);
                }
            });
        }

        // Agents who have since been removed from the roster must not linger on
        // a recomputed run.
        $run->slips()->whereNotIn('employee_id', $employees->pluck('id'))->delete();

        $run->update([
            'status' => 'computed',
            'computed_at' => now(),
            'computed_by_user_id' => $actor?->id,
            'agent_count' => $employees->count(),
            'failed_count' => $failed,
            'total_usd' => round($totals['usd'], 2),
            'total_php' => round($totals['php'], 2),
            'total_card_hold' => round($totals['hold'], 2),
            'total_net' => round($totals['net'], 2),
        ]);

        $run->log('computed', $employees->count() . ' agent(s) read from the CRM'
            . ($failed ? ', ' . $failed . ' could not be read' : ''));

        return $run->refresh();
    }

    /**
     * Locks the figures.
     *
     * Nothing is sent here. Finalizing says the numbers are right; sending is a
     * second, separate decision, so HR can lock a run and check it before an
     * agent ever sees it.
     */
    public function finalize(CommissionRun $run, ?User $actor = null): CommissionRun
    {
        abort_unless($run->status === 'computed', 422, 'Only a computed run can be finalized.');

        abort_if(
            $run->slips()->whereNull('fetch_error')->doesntExist(),
            422,
            'Every agent failed to read from the CRM. Fix the connection and compute again before finalizing.'
        );

        $run->update([
            'status' => 'finalized',
            'finalized_at' => now(),
            'finalized_by_user_id' => $actor?->id,
        ]);

        $run->log('finalized', 'Figures locked');

        return $run->refresh();
    }

    /**
     * Reopens a locked run.
     *
     * Allowed after sending as well, unlike payroll — a commission slip is a
     * statement rather than a payment, so a genuine correction is better
     * reissued than left standing. The reason is recorded.
     *
     * Reopening on its own changes nothing an agent can see; it is recomputing
     * that withdraws the sent slips, because that is the moment the figures
     * actually move.
     */
    public function unlock(CommissionRun $run, User $actor, string $reason): CommissionRun
    {
        abort_unless($run->isFinalized(), 422, 'This run is not locked.');
        abort_if(trim($reason) === '', 422, 'Give a reason for reopening this run.');

        $run->update([
            'status' => 'computed',
            'finalized_at' => null,
            'finalized_by_user_id' => null,
        ]);

        $run->log('reopened', $reason);

        return $run->refresh();
    }

    public function cancel(CommissionRun $run): void
    {
        abort_unless($run->isMutable(), 403, 'A locked run cannot be cancelled.');

        // Hard-deleted so the month is free for a corrected run — the unique
        // key on the run type and period would otherwise block it forever.
        $run->delete();
    }

    /**
     * Everyone who could be put in a run.
     *
     * The whole roster, so an agent whose frequency has not been set yet can
     * still be added by hand rather than being invisible until someone edits
     * their record.
     *
     * @return Collection<int, Employee>
     */
    public function selectableAgents(): Collection
    {
        return Employee::with(['department', 'position'])
            ->whereNull('separation_date')
            ->orderBy('employee_id')
            ->get();
    }

    /** @return array<string, mixed> */
    protected function slipAttributes(Employee $employee, ?\App\Services\Crm\CommissionSlip $slip, ?string $error): array
    {
        return [
            'employee_snapshot' => [
                'name' => $employee->fullName() ?: $employee->employee_id,
                'employee_id' => $employee->employee_id,
                'department' => $employee->department?->name,
                'position' => $employee->position?->title,
                'phone_name' => $employee->phone_name,
            ],
            'agent_name' => $slip?->agentName ?? ($employee->fullName() ?: $employee->employee_id),
            'team' => $slip?->team ?? $employee->department?->name,
            'work_type' => $slip?->workType ?? $employee->workplace_type,

            // The CRM's answer wins. What HR recorded on the employee is the
            // fallback for a slip that could not be fetched, and the two
            // disagreeing is exactly what the slip page points out.
            'commission_scheme' => $slip?->scheme ?? $employee->commission_scheme,
            'scheme_rules' => $slip?->schemeRules ?: null,

            'mtd' => $slip?->mtd,
            'target' => $slip?->target,
            'mtd_percent' => $slip?->mtdPercent,
            'service_commission' => $slip?->serviceCommission,
            'markup_commission' => $slip?->markupCommission,
            'usd_total' => $slip?->usdTotal,
            'exchange_rate' => $slip?->exchangeRate,
            'php_total' => $slip?->phpTotal,
            'card_hold_percent' => $slip?->cardHoldPercent,
            'card_hold_amount' => $slip?->cardHoldAmount,
            'commission_threshold' => $slip?->threshold,
            'threshold_exempt' => (bool) $slip?->thresholdExempt,
            'threshold_applied' => $slip?->thresholdApplied,
            'net_commission' => $slip?->netCommission,
            'statement_supplied' => (bool) $slip?->statementSupplied,
            'transaction_count' => $slip?->transactions->count() ?? 0,
            'fetch_error' => $error,
        ];
    }
}
