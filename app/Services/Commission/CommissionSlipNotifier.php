<?php

namespace App\Services\Commission;

use App\Models\CommissionRun;
use App\Models\CommissionSlip;
use App\Notifications\CommissionSlipReady;

/**
 * Sends the commission slips out.
 *
 * Same two rules as the payslip notifier, for the same reasons:
 *
 * Only slips with no notified_at are picked up, so pressing Send twice reaches
 * the ones that were missed rather than mailing everyone again.
 *
 * Each send is wrapped on its own, and the stamp is written only after the send
 * succeeded. One dead mailbox must not stop the other ninety-nine, and a
 * failure stays in the queue for next time.
 */
class CommissionSlipNotifier
{
    /**
     * @return array{sent: int, skipped: int, failed: int}
     */
    public function sendForRun(CommissionRun $run): array
    {
        abort_unless(
            $run->isFinalized(),
            422,
            'Commission slips can only be sent once the run is finalized.'
        );

        $result = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        $run->slips()
            ->whereNull('notified_at')
            // A slip the CRM could not produce has nothing on it worth sending.
            ->whereNull('fetch_error')
            ->with('employee.user', 'commissionRun')
            ->lazy(50)
            ->each(function (CommissionSlip $slip) use (&$result) {
                $user = $slip->employee?->user;

                if (! $user) {
                    $result['skipped']++;

                    return;
                }

                try {
                    $user->notify(new CommissionSlipReady($slip));
                    $slip->forceFill(['notified_at' => now()])->save();
                    $result['sent']++;
                } catch (\Throwable $e) {
                    report($e);
                    $result['failed']++;
                }
            });

        if ($result['sent'] > 0) {
            $run->update([
                'status' => 'sent',
                'sent_at' => $run->sent_at ?? now(),
                'sent_by_user_id' => $run->sent_by_user_id ?? \Illuminate\Support\Facades\Auth::id(),
            ]);

            $run->log('slips_sent', $result['sent'] . ' slip(s) sent'
                . ($result['skipped'] ? ', ' . $result['skipped'] . ' skipped with no login' : '')
                . ($result['failed'] ? ', ' . $result['failed'] . ' failed' : ''));
        }

        return $result;
    }

    /** How many are still waiting to go out. */
    public function pendingCount(CommissionRun $run): int
    {
        return $run->slips()->whereNull('notified_at')->whereNull('fetch_error')->count();
    }
}
