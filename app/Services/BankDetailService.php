<?php

namespace App\Services;

use App\Models\BankDetailRequest;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\BankDetailActionNeeded;
use App\Notifications\BankDetailChangeNotice;
use App\Notifications\BankDetailStatusUpdated;
use Illuminate\Support\Facades\DB;

/**
 * Where an employee's salary is sent, and the approval around changing it.
 *
 * The first set of details an employee enters is theirs to enter — there is
 * nothing yet to protect. Every change after that has to be approved, because
 * this is the one field in the system that redirects money, and a compromised
 * account would otherwise be enough to divert someone's pay.
 *
 * Two separate audiences, and keeping them apart is the point:
 *
 *   bank_details.approve  decides. Meant for the CEO or COO — deliberately
 *                         someone outside the day-to-day of HR, so the person
 *                         who can edit an employee record is not also the
 *                         person who signs off on moving their pay.
 *
 *   bank_details.notify   is told, and cannot decide. Meant for HR and
 *                         Accounting. A company with no accounting function
 *                         simply grants it to nobody, and nobody is notified —
 *                         no special case needed.
 *
 * The live account lives on the employee record; this table is the trail of
 * who asked for what and who allowed it.
 */
class BankDetailService
{
    /**
     * Files a change, or writes the details straight through on a first entry.
     *
     * Returns the request when one was raised, and null when the details were
     * saved directly.
     */
    public function submit(
        Employee $employee,
        string $bankName,
        string $accountName,
        string $accountNumber,
        ?string $reason = null,
    ): ?BankDetailRequest {
        abort_if(
            $this->pendingFor($employee) !== null,
            422,
            'You already have a bank detail change waiting for approval. Withdraw it first if you need to change it again.'
        );

        $bankName = trim($bankName);
        $accountName = trim($accountName);
        $accountNumber = trim($accountNumber);

        abort_if(
            $this->matchesCurrent($employee, $bankName, $accountName, $accountNumber),
            422,
            'Those are the details already on file — nothing to change.'
        );

        return DB::transaction(function () use ($employee, $bankName, $accountName, $accountNumber, $reason) {
            if (! $this->hasDetails($employee)) {
                $this->write($employee, $bankName, $accountName, $accountNumber);

                // Nothing to approve, but HR and Accounting still want to know
                // an account has been set — it is the first time this person
                // can be paid at all. Recorded as a decided request so the
                // trail starts here rather than at the first change.
                $this->noteFirstEntry($employee, $bankName, $accountName, $accountNumber);

                return null;
            }

            $request = BankDetailRequest::create([
                'employee_id' => $employee->id,
                'bank_name' => $bankName,
                'bank_account_name' => $accountName,
                'bank_account_number' => $accountNumber,
                'previous_bank_name' => $employee->bank_name,
                'previous_bank_account_name' => $employee->bank_account_name,
                'previous_bank_account_number' => $employee->bank_account_number,
                'reason' => $reason,
                'status' => 'pending',
            ]);

            $request->setRelation('employee', $employee);
            $this->notifyApprovers($request);

            return $request;
        });
    }

    /**
     * The approver allows or refuses the change.
     *
     * Approving is what writes to the employee record — nothing before this
     * point has touched where the money goes.
     */
    public function decide(
        BankDetailRequest $request,
        User $actor,
        bool $approved,
        ?string $note = null,
    ): BankDetailRequest {
        abort_unless($request->isPending(), 403, 'This request has already been decided.');
        abort_unless($this->canApprove($actor), 403, 'You cannot decide bank detail changes.');

        return DB::transaction(function () use ($request, $actor, $approved, $note) {
            if ($approved) {
                $this->write(
                    $request->employee,
                    $request->bank_name,
                    $request->bank_account_name,
                    $request->bank_account_number,
                );
            }

            $request->update([
                'status' => $approved ? 'approved' : 'declined',
                'decided_by_user_id' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

            $request->refresh()->loadMissing('employee');
            $this->notifyEmployee($request);

            $name = $request->employee?->fullName() ?? 'An employee';

            $this->notifyWatchers(
                $request,
                $approved
                    ? "{$name}'s payout account has been changed and approved by {$actor->name}. Payroll will use the new account from the next run."
                    : "{$name}'s request to change their payout account was declined by {$actor->name}. Payroll keeps using the account already on file.",
                ($approved ? 'Payout account changed - ' : 'Payout account change declined - ') . $name,
            );

            return $request;
        });
    }

    /** An employee may withdraw their own request while nobody has decided it. */
    public function cancel(BankDetailRequest $request, Employee $actor): void
    {
        abort_unless($request->isPending(), 403, 'Only a waiting request can be withdrawn.');
        abort_unless($request->employee_id === $actor->id, 403, 'You can only withdraw your own request.');

        $request->update(['status' => 'cancelled']);
    }

    public function pendingFor(Employee $employee): ?BankDetailRequest
    {
        return BankDetailRequest::where('employee_id', $employee->id)->pending()->latest()->first();
    }

    public function hasDetails(Employee $employee): bool
    {
        return filled($employee->bank_account_number);
    }

    public function canApprove(User $actor): bool
    {
        return $actor->can('bank_details.approve');
    }

    protected function matchesCurrent(Employee $employee, string $bank, string $name, string $number): bool
    {
        return (string) $employee->bank_name === $bank
            && (string) $employee->bank_account_name === $name
            && (string) $employee->bank_account_number === $number;
    }

    protected function write(Employee $employee, string $bank, string $name, string $number): void
    {
        $employee->update([
            'bank_name' => $bank,
            'bank_account_name' => $name,
            'bank_account_number' => $number,
            'bank_details_updated_at' => now(),
        ]);
    }

    protected function notifyApprovers(BankDetailRequest $request): void
    {
        $name = $request->employee?->fullName() ?? 'An employee';

        User::withPermission('bank_details.approve')->get()
            ->each(fn (User $user) => $this->safeNotify($user, new BankDetailActionNeeded($request)));

        // Everyone else who needs to know. Approvers are excluded because they
        // have just been told the same thing in a message that asks them to
        // act; two notifications for one event trains people to skim.
        $this->notifyWatchers(
            $request,
            "{$name} has asked for their salary to be paid to a different account. It is waiting for approval.",
            "Bank detail change filed - {$name}",
            excludeApprovers: true,
        );
    }

    /**
     * Records a first entry as an already-approved request.
     *
     * Nobody approved it — there was nothing to approve — but without a row
     * there is nothing for the notice to point at, and the history would begin
     * at the first *change* rather than at the account itself.
     */
    protected function noteFirstEntry(Employee $employee, string $bank, string $name, string $number): void
    {
        $request = BankDetailRequest::create([
            'employee_id' => $employee->id,
            'bank_name' => $bank,
            'bank_account_name' => $name,
            'bank_account_number' => $number,
            'reason' => 'First account on file, entered by the employee.',
            'status' => 'approved',
            'decided_at' => now(),
        ]);

        $request->setRelation('employee', $employee);

        $this->notifyWatchers(
            $request,
            $employee->fullName() . ' has entered their payout account for the first time.',
            'Payout account set - ' . $employee->fullName(),
        );
    }

    /**
     * Tells the people who need to know but do not decide.
     *
     * Anyone holding either permission is included by default, so a company
     * that has not separated the roles yet still gets one message rather than
     * none. A company with no accounting function grants the permission to
     * nobody and nobody is notified — that is the whole special case.
     */
    protected function notifyWatchers(
        BankDetailRequest $request,
        string $message,
        string $subject,
        bool $excludeApprovers = false,
    ): void {
        $approvers = User::withPermission('bank_details.approve')->get();
        $recipients = User::withPermission('bank_details.notify')->get();

        // Someone can hold both permissions. When they have already been asked
        // to decide, they have to be taken *out* of this list rather than
        // merely not added to it — they were in it on their own account.
        $recipients = $excludeApprovers
            ? $recipients->reject(fn (User $user) => $approvers->contains('id', $user->id))
            : $recipients->concat($approvers);

        $recipients
            ->unique('id')
            // Nobody needs a memo about their own bank account.
            ->reject(fn (User $user) => $user->id === $request->employee?->user_id)
            ->each(fn (User $user) => $this->safeNotify($user, new BankDetailChangeNotice($request, $message, $subject)));
    }

    protected function notifyEmployee(BankDetailRequest $request): void
    {
        $user = $request->employee?->user;

        if (! $user) {
            return;
        }

        $approved = $request->status === 'approved';

        $message = $approved
            ? 'Your bank details were updated. Your salary now goes to ' . $request->bank_name
                . ' ' . $request->maskedAccount() . '.'
            : 'Your bank detail change was declined. Your salary still goes to the account already on file.';

        $this->safeNotify($user, new BankDetailStatusUpdated(
            $request,
            $message,
            'Your bank details were ' . ($approved ? 'updated' : 'not changed'),
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
