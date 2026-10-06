<?php

namespace App\Services\Commission;

use App\Models\CommissionAdvance;
use App\Models\CommissionAdvancePayment;
use App\Models\CommissionSlip;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Advancing commission an agent has not earned yet, and taking it back.
 *
 * Deliberately the same shape as the payroll cash advance: a principal, an
 * instalment, and repayments that belong to a run so recomputing that run
 * restores the debt exactly rather than charging twice.
 */
class CommissionAdvanceService
{
    public function open(
        Employee $employee,
        float $principal,
        ?float $perRun,
        Carbon|string $releasedOn,
        ?string $note = null,
        ?User $approvedBy = null,
    ): CommissionAdvance {
        abort_if($principal <= 0, 422, 'An advance has to be for an amount.');
        abort_if($perRun !== null && $perRun <= 0, 422, 'The amount per run has to be more than nothing.');

        return CommissionAdvance::create([
            'employee_id' => $employee->id,
            'principal_amount' => round($principal, 2),
            'amount_per_run' => $perRun === null ? null : round($perRun, 2),
            'released_on' => Carbon::parse($releasedOn)->toDateString(),
            'status' => CommissionAdvance::ACTIVE,
            'note' => $note,
            'approved_by_user_id' => $approvedBy?->id,
        ]);
    }

    public function update(CommissionAdvance $advance, ?float $perRun, ?string $note = null): CommissionAdvance
    {
        abort_if($perRun !== null && $perRun <= 0, 422, 'The amount per run has to be more than nothing.');

        $advance->update([
            'amount_per_run' => $perRun === null ? null : round($perRun, 2),
            'note' => $note,
        ]);

        return $advance->fresh();
    }

    /** Pauses collection without forgiving the debt. */
    public function setHold(CommissionAdvance $advance, bool $onHold): CommissionAdvance
    {
        abort_if(
            $advance->status === CommissionAdvance::CANCELLED,
            422,
            'A cancelled advance cannot be put back on collection.',
        );

        $advance->update([
            'status' => $onHold ? CommissionAdvance::ON_HOLD : CommissionAdvance::ACTIVE,
        ]);

        return $this->refreshStatus($advance->fresh());
    }

    /**
     * Writes the rest of it off. Repayments already taken stay taken — they
     * happened, and the agent's slips say so.
     */
    public function cancel(CommissionAdvance $advance): void
    {
        $advance->update(['status' => CommissionAdvance::CANCELLED]);
    }

    /**
     * Everything a run computed on this date should collect.
     *
     * Today, not the month the run covers: an advance handed over after the
     * period ended is still repaid out of that period's commission, because
     * that is the money it was advanced against.
     *
     * @return Collection<int, CommissionAdvance>
     */
    public function collectableFor(Carbon|string $asOf): Collection
    {
        return CommissionAdvance::collectableOn($asOf)
            ->with(['employee', 'payments'])
            ->get()
            ->reject(fn (CommissionAdvance $advance) => $advance->isSettled())
            ->values();
    }

    /**
     * Takes one instalment out of a commission slip.
     *
     * $netCeiling is what the slip can bear. Returns null when there is
     * nothing to take — a month with no commission costs the agent nothing and
     * leaves the balance where it was.
     */
    public function applyToSlip(
        CommissionAdvance $advance,
        CommissionSlip $slip,
        Carbon|string $paidOn,
        float $netCeiling,
    ): ?CommissionAdvancePayment {
        if ($advance->status !== CommissionAdvance::ACTIVE || $advance->isSettled()) {
            return null;
        }

        $amount = $advance->instalmentFor($netCeiling);

        if ($amount <= 0) {
            return null;
        }

        return DB::transaction(function () use ($advance, $slip, $paidOn, $amount) {
            $payment = CommissionAdvancePayment::updateOrCreate(
                ['commission_advance_id' => $advance->id, 'commission_slip_id' => $slip->id],
                [
                    'commission_run_id' => $slip->commission_run_id,
                    'amount' => $amount,
                    'paid_on' => Carbon::parse($paidOn)->toDateString(),
                ],
            );

            $this->refreshStatus($advance->fresh());

            return $payment;
        });
    }

    /**
     * Releases every repayment a run took, so recomputing or cancelling it
     * restores the debt. Advances closed by those repayments reopen.
     */
    public function reverseForRun(int $commissionRunId): int
    {
        return DB::transaction(function () use ($commissionRunId) {
            $advanceIds = CommissionAdvancePayment::forRun($commissionRunId)
                ->pluck('commission_advance_id')
                ->unique();

            $deleted = CommissionAdvancePayment::forRun($commissionRunId)->delete();

            CommissionAdvance::whereIn('id', $advanceIds)
                ->get()
                ->each(fn (CommissionAdvance $advance) => $this->refreshStatus($advance));

            return $deleted;
        });
    }

    /** Flips an advance between active and repaid to match what it has left. */
    public function refreshStatus(CommissionAdvance $advance): CommissionAdvance
    {
        if (in_array($advance->status, [CommissionAdvance::CANCELLED, CommissionAdvance::ON_HOLD], true)) {
            return $advance;
        }

        $advance->load('payments');

        $advance->update([
            'status' => $advance->isSettled() ? CommissionAdvance::PAID : CommissionAdvance::ACTIVE,
        ]);

        return $advance->fresh();
    }
}
