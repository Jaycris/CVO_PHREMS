<?php

namespace App\Notifications;

use App\Models\CommissionRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells whoever releases commission slips that a run has been locked.
 *
 * The commission twin of PayrollReadyToSend, and needed for the same reason:
 * somebody who may only send the slips out never opens the commission screen
 * on their own, because there is nothing else there for them to do.
 *
 * Says how many slips are waiting, and how many agents the CRM could not be
 * read for — the second number is the one worth knowing before pressing send,
 * since those agents get nothing.
 */
class CommissionReadyToSend extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public CommissionRun $run,
        public int $pending,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $label = $this->run->label ?: $this->run->period_start->format('F Y');
        $failed = (int) $this->run->failed_count;
        $url = url('/commissions/runs/' . $this->run->id);

        return (new MailMessage)
            ->subject("Commission locked and ready to send - {$label}")
            ->view('emails.commission-ready-to-send', [
                'run' => $this->run,
                'label' => $label,
                'pending' => $this->pending,
                'failed' => $failed,
                'recipientName' => $notifiable->name ?: 'there',
                'url' => $url,
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'commission_run_id' => $this->run->id,
            'url' => '/commissions/runs/' . $this->run->id,
            'message' => 'Commission for ' . ($this->run->label ?: $this->run->period_start->format('F Y'))
                . ' is locked. ' . $this->pending . ' slip(s) are ready to send.',
        ];
    }
}
