<?php

namespace App\Services\Payroll;

use App\Models\AgentPayment;
use App\Models\CashCategory;
use App\Models\CashEntry;
use App\Models\User;
use App\Notifications\AgentPaySlipReady;
use Illuminate\Support\Facades\DB;

/**
 * Recording pay for agents who are kept out of payroll runs.
 *
 * Three things happen together, and the point of this class is that they
 * cannot drift apart:
 *
 *   The payment is recorded against the agent.
 *   A Money Out entry is written to the cash ledger, naming the agent.
 *   The agent is sent a pay slip.
 *
 * The payment and its ledger entry are written in one transaction. Changing or
 * deleting the payment changes or deletes the entry with it, and the ledger
 * screen refuses to edit an entry that came from here — otherwise the two
 * records disagree and nobody can tell which is right.
 */
class AgentPayService
{
    /** Stored on the ledger entry, so it can be traced back to its payment. */
    public const LEDGER_SOURCE = 'agent_payment';

    public const LEDGER_CATEGORY = 'Agent Pay';

    /**
     * @param  array<string, mixed>  $data
     * @return array{payment: AgentPayment, sent: bool}
     */
    public function record(array $data, User $actor): array
    {
        $payment = DB::transaction(function () use ($data, $actor) {
            $payment = AgentPayment::create($this->paymentAttributes($data) + [
                'recorded_by_user_id' => $actor->id,
            ]);

            $entry = CashEntry::create($this->ledgerAttributes($payment) + [
                'recorded_by_user_id' => $actor->id,
            ]);

            $payment->forceFill(['cash_entry_id' => $entry->id])->save();

            return $payment;
        });

        // Sent after the transaction, not inside it. A slip about a payment
        // that then rolled back would tell the agent they were paid when the
        // record says otherwise.
        $sent = $this->sendSlip($payment);

        return ['payment' => $payment->fresh(['employee', 'cashEntry']), 'sent' => $sent];
    }

    /**
     * Corrects a payment, and its ledger entry with it.
     *
     * The slip is not sent again automatically — fixing a typo in a reference
     * should not email the agent. Send it again deliberately if the amount
     * changed.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(AgentPayment $payment, array $data): AgentPayment
    {
        return DB::transaction(function () use ($payment, $data) {
            $payment->update($this->paymentAttributes($data));
            $payment->refresh();

            $attributes = $this->ledgerAttributes($payment);

            if ($payment->cashEntry) {
                $payment->cashEntry->update($attributes);
            } else {
                // Its entry was removed from the ledger at some point. Put it
                // back, or the company's money out is short by this payment.
                $entry = CashEntry::create($attributes + ['recorded_by_user_id' => $payment->recorded_by_user_id]);
                $payment->forceFill(['cash_entry_id' => $entry->id])->save();
            }

            return $payment->fresh(['employee', 'cashEntry']);
        });
    }

    /** Removes a payment and the Money Out entry it wrote. */
    public function delete(AgentPayment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $payment->cashEntry?->delete();
            $payment->delete();
        });
    }

    /**
     * Sends the agent their slip, by email and to the bell.
     *
     * Returns false when there is nobody to send it to — no login, or a login
     * that has been switched off. The payment still stands; the agent simply
     * has no account to receive it on.
     */
    public function sendSlip(AgentPayment $payment): bool
    {
        $user = $payment->employee?->user;

        if (! $user || ! $user->is_active) {
            return false;
        }

        try {
            $user->notify(new AgentPaySlipReady($payment));
        } catch (\Throwable $e) {
            report($e);

            return false;
        }

        $payment->forceFill(['notified_at' => now()])->save();

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function paymentAttributes(array $data): array
    {
        $blankToNull = fn ($value) => $value === '' ? null : $value;

        return [
            'employee_id' => (int) $data['employee_id'],
            'month' => $data['month'],
            'description' => $data['description'],
            'amount' => round((float) $data['amount'], 2),
            'mtd_usd' => $blankToNull($data['mtd_usd'] ?? null),
            'paid_on' => $data['paid_on'],
            'reference' => $blankToNull($data['reference'] ?? null),
            'note' => $blankToNull($data['note'] ?? null),
        ];
    }

    /**
     * The Money Out entry for a payment.
     *
     * Names the agent, as the company asked. Anyone with Money In & Out access
     * can therefore see who was paid what — a deliberate choice, made knowing
     * that.
     *
     * @return array<string, mixed>
     */
    protected function ledgerAttributes(AgentPayment $payment): array
    {
        $employee = $payment->employee()->first();
        $name = $employee?->fullName() ?: ($employee?->employee_id ?? 'Unknown agent');

        return [
            'entry_date' => $payment->paid_on->toDateString(),
            'direction' => CashEntry::OUT,
            'cash_category_id' => $this->ledgerCategory()->id,
            'description' => 'Agent pay — ' . $name . ' (' . ($employee?->employee_id ?? '?') . '), ' . $payment->monthLabel(),
            'amount' => $payment->amount,
            'reference' => $payment->reference,
            'note' => $payment->description,
            'source_type' => self::LEDGER_SOURCE,
            'source_id' => $payment->id,
        ];
    }

    /** Created the first time it is needed, so a fresh install needs no seeding. */
    protected function ledgerCategory(): CashCategory
    {
        return CashCategory::firstOrCreate(
            ['name' => self::LEDGER_CATEGORY, 'direction' => CashEntry::OUT],
            ['sort_order' => 99, 'is_active' => true],
        );
    }
}
