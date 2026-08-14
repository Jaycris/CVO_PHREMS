<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\ReimbursementRequest;
use App\Models\User;
use App\Notifications\ReimbursementActionNeeded;
use App\Notifications\ReimbursementStatusUpdated;
use App\Support\StoresReceipt;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Claiming back money spent on the company's behalf.
 *
 * Approval and payment are separate steps. Approving says the company owes it;
 * the next payroll run picks it up and pays it. Nothing is paid the moment it
 * is approved, because money leaves on payslips and nowhere else.
 */
class ReimbursementService
{
    use StoresReceipt;

    public function submit(
        Employee $employee,
        float $amount,
        string $expenseDate,
        string $category,
        string $description,
        ?UploadedFile $receipt = null,
    ): ReimbursementRequest {
        abort_if($amount <= 0, 422, 'The amount must be greater than zero.');

        abort_if(
            Carbon::parse($expenseDate)->startOfDay()->isFuture(),
            422,
            'The expense date cannot be in the future.'
        );

        abort_unless(
            array_key_exists($category, ReimbursementRequest::categories()),
            422,
            'Choose what the expense was for.'
        );

        return DB::transaction(function () use ($employee, $amount, $expenseDate, $category, $description, $receipt) {
            $claim = ReimbursementRequest::create([
                'employee_id' => $employee->id,
                'amount_requested' => round($amount, 2),
                'expense_date' => Carbon::parse($expenseDate)->toDateString(),
                'category' => $category,
                'description' => $description,
                'receipt_path' => $this->storeReceipt($employee, $receipt),
                'status' => 'pending',
            ]);

            $claim->setRelation('employee', $employee);
            $this->notifyApprovers($claim);

            return $claim;
        });
    }

    /**
     * An approver may allow less than was claimed — a receipt covering a
     * personal item alongside a company one, say — so the claimed figure is
     * kept beside the approved one rather than overwritten.
     */
    public function decide(
        ReimbursementRequest $claim,
        User $actor,
        bool $approved,
        ?float $amount = null,
        ?string $note = null,
    ): ReimbursementRequest {
        abort_unless($claim->isPending(), 403, 'This claim has already been decided.');
        abort_unless($this->canApprove($actor), 403, 'You cannot decide reimbursement claims.');

        $amount = round($amount ?? (float) $claim->amount_requested, 2);

        if ($approved) {
            abort_if($amount <= 0, 422, 'The approved amount must be greater than zero.');
            abort_if(
                $amount > (float) $claim->amount_requested,
                422,
                'The approved amount cannot exceed what was claimed.'
            );
        }

        return DB::transaction(function () use ($claim, $actor, $approved, $amount, $note) {
            $claim->update([
                'status' => $approved ? 'approved' : 'declined',
                'amount_approved' => $approved ? $amount : null,
                'decided_by_user_id' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

            $claim->refresh()->loadMissing('employee');
            $this->notifyClaimant($claim);

            return $claim;
        });
    }

    /** An employee may withdraw their own claim while it is still pending. */
    public function cancel(ReimbursementRequest $claim, Employee $actor): void
    {
        abort_unless($claim->isPending(), 403, 'Only a pending claim can be withdrawn.');
        abort_unless($claim->employee_id === $actor->id, 403, 'You can only withdraw your own claim.');

        $claim->update(['status' => 'cancelled']);
    }

    /**
     * Approved claims still owed money, for an employee.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ReimbursementRequest>
     */
    public function awaitingPaymentFor(Employee $employee)
    {
        return ReimbursementRequest::where('employee_id', $employee->id)
            ->awaitingPayment()
            ->orderBy('expense_date')
            ->get();
    }

    /** Releases what a payroll run paid, so recomputing pays them again rather than skipping. */
    public function releaseForRun(int $payrollRunId): int
    {
        return ReimbursementRequest::where('payroll_run_id', $payrollRunId)
            ->update(['payroll_run_id' => null, 'payslip_id' => null, 'paid_on' => null]);
    }

    public function canApprove(User $actor): bool
    {
        return $actor->can('reimbursements.approve');
    }

    protected function notifyApprovers(ReimbursementRequest $claim): void
    {
        User::withPermission('reimbursements.approve')->get()
            ->each(fn (User $user) => $this->safeNotify($user, new ReimbursementActionNeeded($claim)));
    }

    protected function notifyClaimant(ReimbursementRequest $claim): void
    {
        $user = $claim->employee?->user;

        if (! $user) {
            return;
        }

        $approved = $claim->status === 'approved';

        $message = $approved
            ? 'Your ' . strtolower($claim->categoryLabel()) . ' claim for PHP '
                . number_format($claim->effectiveAmount(), 2)
                . ' was approved and will be paid on the next payroll.'
            : 'Your ' . strtolower($claim->categoryLabel()) . ' claim for PHP '
                . number_format((float) $claim->amount_requested, 2) . ' was declined.';

        $this->safeNotify($user, new ReimbursementStatusUpdated(
            $claim,
            $message,
            'Your reimbursement claim was ' . ($approved ? 'approved' : 'declined'),
        ));
    }

    /** One unreachable mailbox must not abort the rest. */
    protected function safeNotify(User $user, $notification): void
    {
        try {
            $user->notify($notification);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
