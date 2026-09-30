<?php

namespace App\Services\Payroll;

use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Notifications\PayslipReady;

/**
 * Sends payslips out.
 *
 * Two rules make this safe to run again after a failure:
 *
 * Only payslips with no notified_at are picked up, so a second run reaches the
 * ones that were missed rather than mailing everyone twice.
 *
 * Each send is wrapped on its own. One employee with a dead mailbox must not
 * stop the other ninety-nine, and the stamp is only written once the send
 * actually succeeded, so a failure stays in the queue for next time.
 */
class PayslipNotifier
{
    /**
     * @return array{sent: int, skipped: int, failed: int}
     */
    public function sendForRun(PayrollRun $run): array
    {
        // Nothing goes out before the figures are locked — an employee who is
        // emailed a draft and then sees a different number has every reason to
        // distrust the next one.
        abort_unless(
            in_array($run->status, ['finalized', 'paid'], true),
            422,
            'Payslips can only be sent once the payroll is finalized.'
        );

        $result = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        $run->payslips()
            ->whereNull('notified_at')
            ->with('employee.user', 'payrollRun')
            ->lazy(50)
            ->each(function (Payslip $payslip) use (&$result) {
                $user = $payslip->employee?->user;

                if (! $user) {
                    $result['skipped']++;

                    return;
                }

                try {
                    $user->notify(new PayslipReady($payslip));
                    // The observer allows this one field through on a locked run.
                    $payslip->forceFill(['notified_at' => now()])->save();
                    $result['sent']++;
                } catch (\Throwable $e) {
                    report($e);
                    $result['failed']++;
                }
            });

        if ($result['sent'] > 0) {
            $run->log('payslips_sent', $result['sent'] . ' payslip(s) sent'
                . ($result['skipped'] ? ', ' . $result['skipped'] . ' skipped with no login' : '')
                . ($result['failed'] ? ', ' . $result['failed'] . ' failed' : ''));
        }

        return $result;
    }

    /**
     * Sends one payslip, whether or not it has already gone out.
     *
     * The bulk send deliberately skips anybody already told, so a second click
     * cannot spam a hundred people. This is the other half of that: a figure
     * corrected after the run went out has to reach the one person it belongs
     * to, without emailing everybody else a second copy.
     *
     * @return array{sent: bool, message: string}
     */
    public function sendOne(Payslip $payslip): array
    {
        $run = $payslip->payrollRun;

        abort_unless(
            in_array($run->status, ['finalized', 'paid'], true),
            422,
            'Payslips can only be sent once the payroll is finalized.',
        );

        $payslip->loadMissing('employee.user');
        $user = $payslip->employee?->user;
        $name = $payslip->employeeName();

        if (! $user) {
            return ['sent' => false, 'message' => $name . ' has no PHREMS login yet, so nothing was sent.'];
        }

        $again = $payslip->notified_at !== null;

        try {
            $user->notify(new PayslipReady($payslip));
        } catch (\Throwable $e) {
            report($e);

            return ['sent' => false, 'message' => 'That payslip could not be delivered to ' . $name . '.'];
        }

        // The observer allows this one field through on a locked run.
        $payslip->forceFill(['notified_at' => now()])->save();

        $run->log('payslips_sent', 'Payslip ' . ($again ? 're-sent' : 'sent') . ' to ' . $name);

        return [
            'sent' => true,
            'message' => $again ? 'Payslip sent again to ' . $name . '.' : 'Payslip sent to ' . $name . '.',
        ];
    }

    /** How many are still waiting to go out. */
    public function pendingCount(PayrollRun $run): int
    {
        return $run->payslips()->whereNull('notified_at')->count();
    }
}
