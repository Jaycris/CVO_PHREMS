<?php

namespace App\Notifications;

use App\Models\ReimbursementRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReimbursementStatusUpdated extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ReimbursementRequest $claim,
        public string $message,
        public string $subject,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->subject)
            ->line($this->message);

        if ($this->claim->wasReduced()) {
            $mail->line('Claimed: PHP ' . number_format((float) $this->claim->amount_requested, 2))
                ->line('Approved: PHP ' . number_format($this->claim->effectiveAmount(), 2));
        }

        if ($this->claim->decision_note) {
            $mail->line('Note: ' . $this->claim->decision_note);
        }

        return $mail->action('View Claim', url('/reimbursements'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'reimbursement_request_id' => $this->claim->id,
            'url' => '/reimbursements',
            'message' => $this->message,
        ];
    }
}
