<?php

namespace App\Notifications;

use App\Models\AgentPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an agent their pay slip is ready.
 *
 * Carries no figures, the same rule as the payroll payslip email. It lands in a
 * personal inbox and gets forwarded; the amount belongs behind a login, not in a
 * subject line somebody's family can read over their shoulder.
 */
class AgentPaySlipReady extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public AgentPayment $payment,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $payment = $this->payment->loadMissing('employee');

        return (new MailMessage)
            ->subject('Your pay slip for ' . $payment->monthLabel())
            ->view('emails.agent-pay-slip', [
                'payment' => $payment,
                'employeeName' => $payment->employee?->first_name ?: 'there',
                'url' => url('/my-payslips'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'agent_payment_id' => $this->payment->id,
            'url' => '/my-payslips',
            'message' => 'Your pay slip for ' . $this->payment->monthLabel() . ' is ready.',
        ];
    }
}
