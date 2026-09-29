<?php

namespace App\Notifications;

use App\Models\ReimbursementRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Goes to the person who filed the claim, so it links to My Reimbursement.
 * Sending them to the approval screen would be a 403 — that page is gated on a
 * permission an employee does not hold.
 */
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
        $reduced = $this->claim->wasReduced();

        return (new MailMessage)
            ->subject($this->subject)
            ->view('emails.notice', [
                'heading' => 'Your expense claim was decided',
                'lines' => [
                    $this->message,
                    $reduced ? 'Claimed: PHP ' . number_format((float) $this->claim->amount_requested, 2) : null,
                    $reduced ? 'Approved: PHP ' . number_format($this->claim->effectiveAmount(), 2) : null,
                ],
                'note' => $this->claim->decision_note ? 'Note: ' . $this->claim->decision_note : null,
                'actionLabel' => 'View Claim',
                'url' => url('/my-reimbursements'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'reimbursement_request_id' => $this->claim->id,
            'url' => '/my-reimbursements',
            'message' => $this->message,
        ];
    }
}
