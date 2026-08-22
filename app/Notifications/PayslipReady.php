<?php

namespace App\Notifications;

use App\Models\Payslip;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Queued, unlike most notifications here, because this one goes to everybody at
 * once. A hundred payslips sent inline from a button click would sit on the
 * request until it timed out.
 *
 * The mail deliberately carries no figures. Payslips reach personal inboxes and
 * get forwarded; the amount belongs behind a login, not in a subject line
 * someone's family can read over their shoulder.
 */
class PayslipReady extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Payslip $payslip,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $run = $this->payslip->payrollRun;
        $url = url('/my-payslips/' . $this->payslip->id);

        return (new MailMessage)
            ->subject('Your payslip for ' . $run->periodLabel())
            ->view('emails.payslip-ready', [
                'payslip' => $this->payslip,
                'run' => $run,
                'url' => $url,
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $run = $this->payslip->payrollRun;

        return [
            'payslip_id' => $this->payslip->id,
            'url' => '/my-payslips/' . $this->payslip->id,
            'message' => 'Your payslip for ' . $run->periodLabel() . ' is ready.',
        ];
    }
}
