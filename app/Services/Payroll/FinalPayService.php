<?php

namespace App\Services\Payroll;

use App\Mail\FinalPayStatementMail;
use App\Models\CashAdvance;
use App\Models\CommissionAdvance;
use App\Models\Employee;
use App\Models\FinalPay;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Settling up with somebody who has left.
 *
 * Their thirteenth month, earned up to their last day, less anything they
 * still owe — held until clearance is signed off.
 *
 * Their final salary is deliberately not in here. The regular payroll run
 * already pays the days they worked in their last cutoff, pro-rated to the
 * separation date, and paying it again would be paying twice. What the regular
 * run cannot do is the thirteenth month, because that is only run in December
 * and they will be gone.
 */
class FinalPayService
{
    public function __construct(
        protected ThirteenthMonthService $thirteenthMonth = new ThirteenthMonthService,
    ) {}

    /**
     * Everyone who has left and has nothing settled yet.
     *
     * @return Collection<int, Employee>
     */
    public function awaitingSettlement(): Collection
    {
        return Employee::whereNotNull('separation_date')
            ->whereDoesntHave('finalPays')
            ->with(['department', 'position'])
            ->orderByDesc('separation_date')
            ->get();
    }

    /**
     * What this person is owed, before anything is written down.
     *
     * @return array<string, mixed>
     */
    public function preview(Employee $employee): array
    {
        abort_if($employee->separation_date === null, 422, 'This employee has no separation date.');

        $year = (int) $employee->separation_date->year;
        $earned = $this->thirteenthMonth->basicEarnedFor($employee, $year);

        $cash = $this->cashAdvanceBalance($employee);
        $commission = $this->commissionAdvanceBalance($employee);

        $thirteenth = round($earned['total'] / 12, 2);

        return [
            'for_year' => $year,
            'separation_date' => $employee->separation_date->toDateString(),
            'basic_earned' => $earned['total'],
            'thirteenth_month' => $thirteenth,
            'cash_advance_balance' => $cash,
            'commission_advance_balance' => $commission,
            // Never below zero: a settlement cannot bill somebody. Whatever is
            // still owed after this is a debt to chase, not a negative payslip.
            'net_amount' => (float) max(0, round($thirteenth - $cash - $commission, 2)),
        ];
    }

    /**
     * Writes the settlement down, held for clearance.
     *
     * The figures are stored rather than worked out on the fly: a payroll run
     * finalized next week would otherwise change what somebody was told they
     * were owed after they had left.
     */
    public function record(Employee $employee, ?User $actor = null, ?string $note = null): FinalPay
    {
        $figures = $this->preview($employee);

        abort_if(
            FinalPay::where('employee_id', $employee->id)->where('for_year', $figures['for_year'])->exists(),
            422,
            'This employee already has a final pay recorded for ' . $figures['for_year'] . '.',
        );

        return FinalPay::create($figures + [
            'employee_id' => $employee->id,
            'status' => FinalPay::HELD,
            'note' => $note,
            'created_by_user_id' => $actor?->id,
        ]);
    }

    /** Adds something the company is still owed, or takes it off again. */
    public function setOtherDeduction(FinalPay $finalPay, float $amount, ?string $label): FinalPay
    {
        abort_unless($this->isEditable($finalPay), 422, 'A settlement that has been paid cannot be changed.');
        abort_if($amount < 0, 422, 'A deduction cannot be negative.');

        $finalPay->update([
            'other_deduction' => round($amount, 2),
            'other_deduction_label' => $amount > 0 ? $label : null,
        ]);

        return $this->retotal($finalPay->fresh());
    }

    /** Clearance signed off: it may now be paid. */
    public function clear(FinalPay $finalPay, User $actor): FinalPay
    {
        abort_unless($finalPay->status === FinalPay::HELD, 422, 'Only a held settlement can be cleared.');

        $finalPay->update([
            'status' => FinalPay::CLEARED,
            'cleared_at' => now(),
            'cleared_by_user_id' => $actor->id,
        ]);

        return $finalPay->fresh();
    }

    /** Puts it back on hold, for something found after clearance was given. */
    public function hold(FinalPay $finalPay): FinalPay
    {
        abort_unless($finalPay->status === FinalPay::CLEARED, 422, 'Only a cleared settlement can be put back on hold.');

        $finalPay->update(['status' => FinalPay::HELD, 'cleared_at' => null, 'cleared_by_user_id' => null]);

        return $finalPay->fresh();
    }

    /**
     * The money has gone. Closes the advances it settled, so nothing keeps
     * chasing a balance that was paid off out of this.
     */
    public function release(FinalPay $finalPay, Carbon|string $releasedOn): FinalPay
    {
        abort_unless($finalPay->isPayable(), 422, 'This settlement has not been cleared yet.');

        return DB::transaction(function () use ($finalPay, $releasedOn) {
            $finalPay->update([
                'status' => FinalPay::RELEASED,
                'released_on' => Carbon::parse($releasedOn)->toDateString(),
            ]);

            CashAdvance::where('employee_id', $finalPay->employee_id)
                ->whereIn('status', ['active', 'on_hold'])
                ->update(['status' => 'paid']);

            CommissionAdvance::where('employee_id', $finalPay->employee_id)
                ->whereIn('status', [CommissionAdvance::ACTIVE, CommissionAdvance::ON_HOLD])
                ->update(['status' => CommissionAdvance::PAID]);

            return $finalPay->fresh();
        });
    }

    /**
     * Emails the statement to the address they can still read.
     *
     * Their personal address, not the company one — that mailbox is closed by
     * the time this is paid, and their PHREMS login with it.
     *
     * @return array{sent: bool, message: string}
     */
    public function emailStatement(FinalPay $finalPay): array
    {
        $employee = $finalPay->employee;
        $name = $employee?->fullName() ?: 'This employee';
        $address = $employee?->personal_email;

        if (blank($address)) {
            return [
                'sent' => false,
                'message' => $name . ' has no personal email on file, so nothing was sent. Add one on their profile.',
            ];
        }

        try {
            Mail::to($address)->queue(new FinalPayStatementMail($finalPay->load('employee')));
        } catch (\Throwable $e) {
            report($e);

            return ['sent' => false, 'message' => 'That statement could not be sent to ' . $address . '.'];
        }

        $again = $finalPay->emailed_at !== null;

        $finalPay->update(['emailed_at' => now()]);

        return [
            'sent' => true,
            'message' => ($again ? 'Statement sent again to ' : 'Statement sent to ') . $address . '.',
        ];
    }

    public function cancel(FinalPay $finalPay, ?string $note = null): FinalPay
    {
        abort_if($finalPay->status === FinalPay::RELEASED, 422, 'A settlement already paid cannot be cancelled.');

        $finalPay->update(['status' => FinalPay::CANCELLED, 'note' => $note ?? $finalPay->note]);

        return $finalPay->fresh();
    }

    /**
     * Brings the figures up to date with the payroll runs finalized since.
     *
     * Somebody who left on the 16th usually has their last cutoff finalized
     * days later, and that payslip is part of the thirteenth month they are
     * owed. Without this the settlement would be short by the last half-month.
     */
    public function recalculate(FinalPay $finalPay): FinalPay
    {
        abort_unless($this->isEditable($finalPay), 422, 'A settlement that has been paid cannot be changed.');

        $figures = $this->preview($finalPay->employee);

        $finalPay->update([
            'basic_earned' => $figures['basic_earned'],
            'thirteenth_month' => $figures['thirteenth_month'],
            'cash_advance_balance' => $figures['cash_advance_balance'],
            'commission_advance_balance' => $figures['commission_advance_balance'],
        ]);

        return $this->retotal($finalPay->fresh());
    }

    protected function retotal(FinalPay $finalPay): FinalPay
    {
        $finalPay->update([
            'net_amount' => (float) max(0, round((float) $finalPay->thirteenth_month - $finalPay->totalDeductions(), 2)),
        ]);

        return $finalPay->fresh();
    }

    protected function isEditable(FinalPay $finalPay): bool
    {
        return in_array($finalPay->status, [FinalPay::HELD, FinalPay::CLEARED], true);
    }

    protected function cashAdvanceBalance(Employee $employee): float
    {
        return round(
            CashAdvance::where('employee_id', $employee->id)
                ->whereIn('status', ['active', 'on_hold'])
                ->with('payments')
                ->get()
                ->sum(fn (CashAdvance $advance) => $advance->remainingBalance()),
            2,
        );
    }

    protected function commissionAdvanceBalance(Employee $employee): float
    {
        return round(
            CommissionAdvance::where('employee_id', $employee->id)
                ->whereIn('status', [CommissionAdvance::ACTIVE, CommissionAdvance::ON_HOLD])
                ->with('payments')
                ->get()
                ->sum(fn (CommissionAdvance $advance) => $advance->balance()),
            2,
        );
    }
}
