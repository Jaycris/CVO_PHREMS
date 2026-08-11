<?php

namespace App\Services;

use App\Models\CashAdvance;
use App\Models\CashAdvancePayment;
use App\Models\CashAdvanceRequest;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\CashAdvanceRequestActionNeeded;
use App\Notifications\CashAdvanceRequestStatusUpdated;
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
        string $source = 'hr_recorded',
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
            'source' => $source,
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

    // ---------------------------------------------------------------------
    // Request and approval — Manager, then CEO/COO
    // ---------------------------------------------------------------------

    /**
     * Files an application. Nothing is owed and nothing appears in the
     * repayment register until both approvers have signed off; the advance
     * itself is only opened in ceoDecide().
     */
    public function submitRequest(
        Employee $employee,
        float $amount,
        float $perCutoff,
        ?string $neededBy,
        string $reason,
    ): CashAdvanceRequest {
        abort_if($amount <= 0, 422, 'The advance amount must be greater than zero.');
        abort_if($perCutoff <= 0, 422, 'The per-cutoff deduction must be greater than zero.');
        abort_if($perCutoff > $amount, 422, 'The per-cutoff deduction cannot exceed the advance amount.');

        $pending = CashAdvanceRequest::where('employee_id', $employee->id)
            ->whereIn('status', ['pending_manager', 'pending_ceo'])
            ->exists();

        abort_if($pending, 422, 'You already have a cash advance request awaiting approval.');

        return DB::transaction(function () use ($employee, $amount, $perCutoff, $neededBy, $reason) {
            $request = CashAdvanceRequest::create([
                'employee_id' => $employee->id,
                'amount_requested' => round($amount, 2),
                'per_cutoff_requested' => round($perCutoff, 2),
                'needed_by' => $neededBy ? Carbon::parse($neededBy)->toDateString() : null,
                'reason' => $reason,
                'status' => 'pending_manager',
                'manager_id' => $employee->reportsTo?->id,
            ]);

            $request->setRelation('employee', $employee);

            $managerUser = $request->manager?->user;

            $this->notifyApprover($request, $managerUser);

            // When there is no manager the fallback above already reaches
            // HR/Admin; sending the informational copy too would just duplicate.
            if ($managerUser) {
                $this->notifyHrOfSubmission($request);
            }

            return $request;
        });
    }

    /**
     * First tier. An approver may release less than was asked for, or stretch
     * the repayment — the figures they set are what the CEO then sees and what
     * ultimately opens the advance.
     */
    public function managerDecide(
        CashAdvanceRequest $request,
        Employee $actor,
        bool $approved,
        ?float $amount = null,
        ?float $perCutoff = null,
        ?string $note = null,
    ): void {
        abort_unless($request->status === 'pending_manager', 403, 'This request is no longer awaiting manager approval.');
        abort_unless($this->canDecideAsManager($request, $actor), 403, 'You cannot decide this cash advance request.');

        [$amount, $perCutoff] = $approved
            ? $this->validateApprovedFigures($request, $amount, $perCutoff)
            : [null, null];

        DB::transaction(function () use ($request, $actor, $approved, $amount, $perCutoff, $note) {
            $request->update([
                'status' => $approved ? 'pending_ceo' : 'declined',
                'amount_approved' => $amount,
                'per_cutoff_approved' => $perCutoff,
                'manager_id' => $request->manager_id ?? $actor->id,
                'manager_decision' => $approved ? 'approved' : 'declined',
                'manager_decided_at' => now(),
                'manager_note' => $note,
            ]);

            $request->refresh()->loadMissing('employee');

            if ($approved) {
                $this->notifyApprover($request, $this->ceoApprover()?->user);
                $this->notifyRequestor($request, 'endorsed by your manager and is now with the CEO/COO');
            } else {
                $this->notifyRequestor($request, 'declined by your manager');
                $this->notifyHrOfOutcome($request);
            }
        });
    }

    /**
     * Final tier. Approval is the moment money is committed, so this is where
     * the CashAdvance row is created and the repayment schedule begins.
     */
    public function ceoDecide(
        CashAdvanceRequest $request,
        User $actor,
        bool $approved,
        ?float $amount = null,
        ?float $perCutoff = null,
        ?string $note = null,
        ?string $startDate = null,
    ): void {
        abort_unless($request->status === 'pending_ceo', 403, 'This request is not awaiting CEO/COO approval.');
        abort_unless($actor->hasAnyRole(['CEO', 'Admin']), 403, 'Only the CEO/COO can give final approval.');

        [$amount, $perCutoff] = $approved
            ? $this->validateApprovedFigures($request, $amount, $perCutoff)
            : [$request->amount_approved, $request->per_cutoff_approved];

        DB::transaction(function () use ($request, $actor, $approved, $amount, $perCutoff, $note, $startDate) {
            $advance = null;

            if ($approved) {
                $advance = $this->open(
                    $request->employee,
                    (float) $amount,
                    (float) $perCutoff,
                    $startDate ?: now()->toDateString(),
                    'Approved cash advance request #' . $request->id,
                    $actor,
                    source: 'requested',
                );
            }

            $request->update([
                'status' => $approved ? 'approved' : 'declined',
                'amount_approved' => $approved ? $amount : $request->amount_approved,
                'per_cutoff_approved' => $approved ? $perCutoff : $request->per_cutoff_approved,
                'ceo_id' => $actor->employee?->id,
                'ceo_decision' => $approved ? 'approved' : 'declined',
                'ceo_decided_at' => now(),
                'ceo_note' => $note,
                'cash_advance_id' => $advance?->id,
            ]);

            $request->refresh()->loadMissing('employee');

            $this->notifyRequestor(
                $request,
                $approved ? 'approved' : 'declined by the CEO/COO'
            );

            $this->notifyHrOfOutcome($request);
        });
    }

    /** An employee may withdraw their own request while it is still pending. */
    public function cancelRequest(CashAdvanceRequest $request, Employee $actor): void
    {
        abort_unless($request->isPending(), 403, 'Only a pending request can be cancelled.');
        abort_unless($request->employee_id === $actor->id, 403, 'You can only cancel your own request.');

        $request->update(['status' => 'cancelled']);
    }

    /**
     * Whoever the request routes to may decide it. When an employee has no
     * manager on file it falls to HR/Admin rather than becoming undecidable.
     */
    protected function canDecideAsManager(CashAdvanceRequest $request, Employee $actor): bool
    {
        if ($request->manager_id !== null) {
            return $request->manager_id === $actor->id;
        }

        return (bool) $actor->user?->hasAnyRole(['HR', 'Admin', 'CEO']);
    }

    /**
     * @return array{0: float, 1: float}
     */
    protected function validateApprovedFigures(CashAdvanceRequest $request, ?float $amount, ?float $perCutoff): array
    {
        $amount = round($amount ?? $request->effectiveAmount(), 2);
        $perCutoff = round($perCutoff ?? $request->effectivePerCutoff(), 2);

        abort_if($amount <= 0, 422, 'The approved amount must be greater than zero.');
        abort_if($amount > (float) $request->amount_requested, 422, 'The approved amount cannot exceed the amount requested.');
        abort_if($perCutoff <= 0, 422, 'The per-cutoff deduction must be greater than zero.');
        abort_if($perCutoff > $amount, 422, 'The per-cutoff deduction cannot exceed the approved amount.');

        return [$amount, $perCutoff];
    }

    /** The employee who gives final approval — the CEO, or the COO standing in. */
    protected function ceoApprover(): ?Employee
    {
        return User::role('CEO')->first()?->employee;
    }

    protected function notifyApprover(CashAdvanceRequest $request, ?User $approverUser): void
    {
        if ($approverUser) {
            $this->safeNotify($approverUser, new CashAdvanceRequestActionNeeded($request));

            return;
        }

        // No approver on file at this tier — fall back to HR/Admin so the
        // request does not sit unseen.
        User::role(['HR', 'Admin'])->get()
            ->each(fn (User $user) => $this->safeNotify($user, new CashAdvanceRequestActionNeeded($request)));
    }

    protected function notifyRequestor(CashAdvanceRequest $request, string $summary): void
    {
        $user = $request->employee?->user;

        if (! $user) {
            return;
        }

        $this->safeNotify($user, new CashAdvanceRequestStatusUpdated(
            $request,
            "Your cash advance request for PHP " . number_format((float) $request->amount_requested, 2) . " was {$summary}.",
            'Your cash advance request was ' . ($request->status === 'approved' ? 'approved' : 'updated'),
        ));
    }

    /**
     * HR carries out the payout and the payroll deduction, so they are copied
     * when a request is filed and again when it is finally settled — not on the
     * intermediate manager step, which would be noise.
     */
    protected function notifyHrOfSubmission(CashAdvanceRequest $request): void
    {
        $name = $this->employeeName($request);
        $amount = number_format((float) $request->amount_requested, 2);

        $this->notifyHr($request, "{$name} filed a cash advance request for PHP {$amount}.", "New cash advance request - {$name}");
    }

    protected function notifyHrOfOutcome(CashAdvanceRequest $request): void
    {
        $name = $this->employeeName($request);

        $message = $request->status === 'approved'
            ? "{$name}'s cash advance for PHP " . number_format($request->effectiveAmount(), 2)
                . ' was approved. It is now in the register and will be deducted at PHP '
                . number_format($request->effectivePerCutoff(), 2) . ' per cutoff.'
            : "{$name}'s cash advance request was declined.";

        $this->notifyHr($request, $message, "Cash advance request {$request->status} - {$name}");
    }

    protected function notifyHr(CashAdvanceRequest $request, string $message, string $subject): void
    {
        // Admin is included because a company this size may have no dedicated
        // HR account, and an unseen advance request stalls someone's payout.
        User::role(['HR', 'Admin'])->get()
            ->each(fn (User $user) => $this->safeNotify(
                $user,
                new CashAdvanceRequestStatusUpdated($request, $message, $subject)
            ));
    }

    protected function employeeName(CashAdvanceRequest $request): string
    {
        $employee = $request->employee;

        return $employee?->fullName() ?: ($employee?->employee_id ?? 'An employee');
    }

    /** One unreachable mailbox must not abort the rest of the notifications. */
    protected function safeNotify(User $user, $notification): void
    {
        try {
            $user->notify($notification);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function generateReference(): string
    {
        do {
            $reference = 'CA-' . now()->format('Y') . '-' . random_int(1000, 9999);
        } while (CashAdvance::where('reference_no', $reference)->exists());

        return $reference;
    }
}
