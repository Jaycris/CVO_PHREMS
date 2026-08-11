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
        string $deductionPlan = 'split_two_cutoffs',
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
            'deduction_plan' => $deductionPlan,
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
    // Request and approval — filed by the employee, decided by the CEO/COO
    // ---------------------------------------------------------------------

    /**
     * Files an application. Nothing is owed and nothing appears in the
     * repayment register until it is approved; the advance itself is only
     * opened in decide().
     */
    public function submitRequest(
        Employee $employee,
        float $amount,
        string $deductionPlan,
        ?string $neededBy,
        string $reason,
    ): CashAdvanceRequest {
        abort_if($amount <= 0, 422, 'The advance amount must be greater than zero.');

        $cap = $this->maxRequestAmount();

        abort_if(
            $amount > $cap,
            422,
            'A cash advance request cannot exceed PHP ' . number_format($cap, 2) . '.'
        );

        $this->guardDeductionPlan($deductionPlan);

        $pending = CashAdvanceRequest::where('employee_id', $employee->id)
            ->where('status', 'pending')
            ->exists();

        abort_if($pending, 422, 'You already have a cash advance request awaiting approval.');

        return DB::transaction(function () use ($employee, $amount, $deductionPlan, $neededBy, $reason) {
            $request = CashAdvanceRequest::create([
                'employee_id' => $employee->id,
                'amount_requested' => round($amount, 2),
                'deduction_plan' => $deductionPlan,
                'needed_by' => $neededBy ? Carbon::parse($neededBy)->toDateString() : null,
                'reason' => $reason,
                'status' => 'pending',
            ]);

            $request->setRelation('employee', $employee);

            $this->notifyApprovers($request);
            $this->notifyBackOfficeOfSubmission($request);

            return $request;
        });
    }

    /**
     * HR, the accountant and the CEO/COO may all revise what will be released
     * and how it is recovered, before a decision is made. The employee's
     * original figure is never overwritten, so the change stays visible.
     *
     * The employee request cap deliberately does not apply here: these are the
     * people authorising the money, and a genuine emergency should not need a
     * config change to approve.
     */
    public function amendRequest(
        CashAdvanceRequest $request,
        User $actor,
        float $amount,
        ?string $deductionPlan = null,
    ): CashAdvanceRequest {
        abort_unless($request->isPending(), 403, 'Only a pending request can be amended.');
        abort_unless($this->canAmend($actor), 403, 'You cannot change the amount on this request.');
        abort_if($amount <= 0, 422, 'The amount must be greater than zero.');

        if ($deductionPlan !== null) {
            $this->guardDeductionPlan($deductionPlan);
        }

        $request->update([
            'amount_approved' => round($amount, 2),
            'deduction_plan' => $deductionPlan ?? $request->deduction_plan,
            'amended_by_user_id' => $actor->id,
            'amended_at' => now(),
        ]);

        return $request->fresh();
    }

    /**
     * The CEO/COO decides. Approval is the moment money is committed, so this is
     * where the CashAdvance row is created and the repayment schedule begins.
     */
    public function decide(
        CashAdvanceRequest $request,
        User $actor,
        bool $approved,
        ?float $amount = null,
        ?string $deductionPlan = null,
        ?string $note = null,
        ?string $startDate = null,
    ): void {
        abort_unless($request->isPending(), 403, 'This request has already been decided.');
        abort_unless($this->canApprove($actor), 403, 'Only the CEO/COO can approve a cash advance.');

        if ($deductionPlan !== null) {
            $this->guardDeductionPlan($deductionPlan);
        }

        $plan = $deductionPlan ?? $request->deduction_plan;
        $amount = round($amount ?? $request->effectiveAmount(), 2);

        if ($approved) {
            abort_if($amount <= 0, 422, 'The approved amount must be greater than zero.');
        }

        DB::transaction(function () use ($request, $actor, $approved, $amount, $plan, $note, $startDate) {
            $advance = null;

            if ($approved) {
                $advance = $this->open(
                    $request->employee,
                    $amount,
                    CashAdvanceRequest::perCutoffFor($amount, $plan),
                    $startDate ?: now()->toDateString(),
                    'Approved cash advance request #' . $request->id,
                    $actor,
                    source: 'requested',
                    deductionPlan: $plan,
                );
            }

            $request->update([
                'status' => $approved ? 'approved' : 'declined',
                'amount_approved' => $approved ? $amount : $request->amount_approved,
                'deduction_plan' => $plan,
                'decided_by_user_id' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $note,
                'cash_advance_id' => $advance?->id,
            ]);

            $request->refresh()->loadMissing('employee');

            $this->notifyRequestor($request);
            $this->notifyBackOfficeOfOutcome($request);
        });
    }

    /** An employee may withdraw their own request while it is still pending. */
    public function cancelRequest(CashAdvanceRequest $request, Employee $actor): void
    {
        abort_unless($request->isPending(), 403, 'Only a pending request can be cancelled.');
        abort_unless($request->employee_id === $actor->id, 403, 'You can only cancel your own request.');

        $request->update(['status' => 'cancelled']);
    }

    public function maxRequestAmount(): float
    {
        return (float) config('payroll.cash_advance.max_request_amount', 3000);
    }

    public function canApprove(User $actor): bool
    {
        return $actor->can('cash_advances.approve');
    }

    public function canAmend(User $actor): bool
    {
        // Approving implies being able to set the figure being approved.
        return $actor->can('cash_advances.amend') || $this->canApprove($actor);
    }

    protected function guardDeductionPlan(string $plan): void
    {
        abort_unless(
            array_key_exists($plan, CashAdvanceRequest::deductionPlans()),
            422,
            'Choose how the advance will be deducted from your salary.'
        );
    }

    protected function notifyApprovers(CashAdvanceRequest $request): void
    {
        User::withPermission('cash_advances.approve')->get()
            ->each(fn (User $user) => $this->safeNotify($user, new CashAdvanceRequestActionNeeded($request)));
    }

    protected function notifyRequestor(CashAdvanceRequest $request): void
    {
        $user = $request->employee?->user;

        if (! $user) {
            return;
        }

        $approved = $request->status === 'approved';

        $message = $approved
            ? 'Your cash advance for PHP ' . number_format($request->effectiveAmount(), 2)
                . ' was approved, deducted at PHP ' . number_format($request->perCutoffAmount(), 2)
                . ' per cutoff.'
            : 'Your cash advance request for PHP ' . number_format((float) $request->amount_requested, 2)
                . ' was declined.';

        $this->safeNotify($user, new CashAdvanceRequestStatusUpdated(
            $request,
            $message,
            'Your cash advance request was ' . ($approved ? 'approved' : 'declined'),
        ));
    }

    /**
     * HR and the accountant carry out the payout and the payroll deduction, so
     * they are copied when a request is filed and again when it is decided.
     */
    protected function notifyBackOfficeOfSubmission(CashAdvanceRequest $request): void
    {
        $name = $this->employeeName($request);
        $amount = number_format((float) $request->amount_requested, 2);

        $this->notifyBackOffice(
            $request,
            "{$name} filed a cash advance request for PHP {$amount}.",
            "New cash advance request - {$name}",
        );
    }

    protected function notifyBackOfficeOfOutcome(CashAdvanceRequest $request): void
    {
        $name = $this->employeeName($request);

        $message = $request->status === 'approved'
            ? "{$name}'s cash advance for PHP " . number_format($request->effectiveAmount(), 2)
                . ' was approved. It is now in the register and will be deducted at PHP '
                . number_format($request->perCutoffAmount(), 2) . ' per cutoff.'
            : "{$name}'s cash advance request was declined.";

        $this->notifyBackOffice($request, $message, "Cash advance request {$request->status} - {$name}");
    }

    /**
     * Whoever oversees cash advances gets a copy. Resolving recipients by
     * permission rather than by a stored list is what lets a future hire — an
     * accountant, say — start receiving these the moment their position is
     * granted the permission. No code change needed.
     */
    protected function notifyBackOffice(CashAdvanceRequest $request, string $message, string $subject): void
    {
        User::withPermission('cash_advances.view_all')->get()
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
