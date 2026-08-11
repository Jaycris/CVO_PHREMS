<?php

namespace App\Services;

use App\Models\CashAdvance;
use App\Models\CashAdvancePayment;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CashAdvanceService
{
    public function open(
        Employee $employee,
        float $principal,
        float $perCutoff,
        string $startDate,
        ?string $note,
        ?User $actor = null,
        ?string $referenceNo = null,
    ): CashAdvance {
        abort_if($principal <= 0, 422, 'The advance amount must be greater than zero.');
        abort_if($perCutoff <= 0, 422, 'The per-cutoff deduction must be greater than zero.');
        abort_if($perCutoff > $principal, 422, 'The per-cutoff deduction cannot exceed the advance amount.');

        return CashAdvance::create([
            'employee_id' => $employee->id,
            'reference_no' => $referenceNo ?: $this->generateReference(),
            'principal_amount' => round($principal, 2),
            'amount_per_cutoff' => round($perCutoff, 2),
            'start_date' => Carbon::parse($startDate)->toDateString(),
            'status' => 'active',
            'approved_by_user_id' => $actor?->id,
            'note' => $note,
        ]);
    }

    /**
     * The principal is deliberately not editable once money has been handed
     * over — correcting it would silently change what is already owed. Only the
     * repayment pace, the note and the hold state can change.
     */
    public function update(CashAdvance $advance, float $perCutoff, ?string $note = null): CashAdvance
    {
        abort_if($advance->status === 'cancelled', 403, 'A cancelled advance cannot be edited.');
        abort_if($perCutoff <= 0, 422, 'The per-cutoff deduction must be greater than zero.');

        $advance->update([
            'amount_per_cutoff' => round($perCutoff, 2),
            'note' => $note ?? $advance->note,
        ]);

        return $advance->fresh();
    }

    public function setHold(CashAdvance $advance, bool $onHold): CashAdvance
    {
        abort_if($advance->status === 'cancelled', 403, 'A cancelled advance cannot be put on hold.');
        abort_if($advance->isSettled(), 403, 'This advance is already fully repaid.');

        $advance->update(['status' => $onHold ? 'on_hold' : 'active']);

        return $advance->fresh();
    }

    /**
     * Cancelling writes off whatever is still outstanding. It is refused once
     * repayments exist, because those rows are referenced by finalised payslips
     * — reduce the balance with a payment correction instead.
     */
    public function cancel(CashAdvance $advance): void
    {
        abort_if($advance->payments()->exists(), 403, 'This advance has already been partly repaid and cannot be cancelled.');

        $advance->update(['status' => 'cancelled']);
    }

    /** Advances a payroll run should deduct from, for a period ending on the given date. */
    public function activeForPeriod(Carbon|string $periodEnd): Collection
    {
        return CashAdvance::activeOn($periodEnd)
            ->with(['employee', 'payments'])
            ->get()
            ->reject(fn (CashAdvance $advance) => $advance->isSettled())
            ->values();
    }

    /**
     * Deducts one instalment from a payslip.
     *
     * $netCeiling is what the payslip can still bear after every other
     * deduction. The instalment shrinks to fit rather than driving net pay
     * negative — the shortfall simply stays on the balance for the next cutoff.
     * Returns null when nothing can be taken.
     */
    public function applyToPayslip(
        CashAdvance $advance,
        int $payrollRunId,
        int $payslipId,
        Carbon|string $paidOn,
        float $netCeiling,
    ): ?CashAdvancePayment {
        if ($advance->status !== 'active' || $advance->isSettled()) {
            return null;
        }

        $amount = $advance->instalmentFor($netCeiling);

        if ($amount <= 0) {
            return null;
        }

        return DB::transaction(function () use ($advance, $payrollRunId, $payslipId, $paidOn, $amount) {
            // updateOrCreate keeps a recompute idempotent: computing the same
            // run twice must not charge the employee twice. The unique index on
            // [cash_advance_id, payslip_id] is the hard guarantee.
            $payment = CashAdvancePayment::updateOrCreate(
                ['cash_advance_id' => $advance->id, 'payslip_id' => $payslipId],
                [
                    'payroll_run_id' => $payrollRunId,
                    'amount' => $amount,
                    'paid_on' => Carbon::parse($paidOn)->toDateString(),
                ]
            );

            $this->refreshStatus($advance->fresh());

            return $payment;
        });
    }

    /**
     * Releases every repayment a payroll run took, so recomputing or
     * unfinalising the run restores the debt exactly. Advances that were closed
     * by those payments reopen.
     */
    public function reverseForRun(int $payrollRunId): int
    {
        return DB::transaction(function () use ($payrollRunId) {
            $advanceIds = CashAdvancePayment::forRun($payrollRunId)
                ->pluck('cash_advance_id')
                ->unique();

            $deleted = CashAdvancePayment::forRun($payrollRunId)->delete();

            CashAdvance::whereIn('id', $advanceIds)
                ->get()
                ->each(fn (CashAdvance $advance) => $this->refreshStatus($advance));

            return $deleted;
        });
    }

    /** Flips an advance between active and paid to match its derived balance. */
    public function refreshStatus(CashAdvance $advance): CashAdvance
    {
        if ($advance->status === 'cancelled' || $advance->status === 'on_hold') {
            return $advance;
        }

        $shouldBe = $advance->isSettled() ? 'paid' : 'active';

        if ($advance->status !== $shouldBe) {
            $advance->update(['status' => $shouldBe]);
        }

        return $advance->fresh();
    }

    public function refreshStatuses(Collection $advances): void
    {
        $advances->each(fn (CashAdvance $advance) => $this->refreshStatus($advance));
    }

    protected function generateReference(): string
    {
        do {
            $reference = 'CA-' . now()->format('Y') . '-' . random_int(1000, 9999);
        } while (CashAdvance::where('reference_no', $reference)->exists());

        return $reference;
    }
}
