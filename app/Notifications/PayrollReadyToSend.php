<?php

namespace App\Notifications;

use App\Models\PayrollRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells whoever releases payslips that a run has been locked.
 *
 * The handoff needs this. Somebody who may only send payslips has no reason to
 * be sitting on the payroll screen — they cannot open a run or compute one, so
 * nothing takes them there. Without a message, the CEO finalizes and then has
 * to remember to tell HR by hand, which is the step that gets forgotten on the
 * one afternoon everybody is busy.
 *
 * No figures. This says a run is ready and how many people are waiting; the
 * pay itself is on the screen behind a permission, not in an inbox.
 */
class PayrollReadyToSend extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public PayrollRun $run,
        public int $pending,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $period = $this->run->periodLabel();
        $url = url('/payroll/runs/' . $this->run->id);

        return (new MailMessage)
            ->subject("Payroll locked and ready to send - {$period}")
            ->view('emails.payroll-ready-to-send', [
                'run' => $this->run,
                'period' => $period,
                'pending' => $this->pending,
                'recipientName' => $notifiable->name ?: 'there',
                'url' => $url,
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'payroll_run_id' => $this->run->id,
            'url' => '/payroll/runs/' . $this->run->id,
            'message' => 'Payroll for ' . $this->run->periodLabel() . ' is locked. '
                . $this->pending . ' payslip(s) are ready to send.',
        ];
    }
}
