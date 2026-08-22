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

    public function openRun(Carbon|string $month, ?User $actor = null): CommissionRun
    {
        $start = Carbon::parse($month)->startOfMonth();

        abort_if(
            $start->gt(Carbon::now('Asia/Manila')->startOfMonth()),
            422,
            'That month has not started yet.'
        );

        $existing = CommissionRun::forMonth($start)->first();

        if ($existing) {
            return $existing;
        }

        $run = CommissionRun::create([
            'period_month' => $start->toDateString(),
            'status' => 'draft',
        ]);

        $run->log('opened', 'Run opened for ' . $start->format('F Y'));

        return $run;
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

        $employees = $this->eligibleAgents();

        abort_if($employees->isEmpty(), 422, 'There are no employees to compute commissions for.');

        $month = $run->month();
        $totals = ['usd' => 0.0, 'php' => 0.0, 'hold' => 0.0, 'net' => 0.0];
        $failed = 0;

        foreach ($employees as $employee) {
            // Outside the transaction on purpose — an HTTP call held inside one
            // keeps a database lock open for as long as the CRM takes to answer.
            $this->crm->forget($employee, $month);

            try {
                $slip = $this->crm->forEmployee($employee, $month);
                $error = null;
            } catch (CrmUnavailable $e) {
                $slip = null;
                $error = $e->getMessage();
                $failed++;
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
     * reissued than left standing. The reason is recorded, and agents who were
     * already sent the old figures keep seeing them until it is sent again.
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
        // key on period_month would otherwise block it forever.
        $run->delete();
    }

    /**
     * Who a run covers.
     *
     * Everyone still employed. An agent with no commission simply gets a slip
     * of zeroes from the CRM, which is a truthful answer and cheaper to read
     * than to maintain a list of who is on commission.
     *
     * @return Collection<int, Employee>
     */
    public function eligibleAgents(): Collection
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
            'net_commission' => $slip?->netCommission,
            'statement_supplied' => (bool) $slip?->statementSupplied,
            'transaction_count' => $slip?->transactions->count() ?? 0,
            'fetch_error' => $error,
        ];
    }
}
