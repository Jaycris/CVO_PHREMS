<?php

namespace App\Notifications;

use App\Models\CashAdvanceRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One notification serves both the employee and HR: the two audiences want the
 * same facts phrased for their side of the transaction, so the caller supplies
 * the wording rather than this class guessing from the notifiable's role.
 */
class CashAdvanceRequestStatusUpdated extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public CashAdvanceRequest $advanceRequest,
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
        $request = $this->advanceRequest;

        $mail = (new MailMessage)
            ->subject($this->subject)
            ->line($this->message);

        if ($request->status === 'approved') {
            $mail->line('Amount: PHP ' . number_format($request->effectiveAmount(), 2))
                ->line('Deducted per cutoff: PHP ' . number_format($request->effectivePerCutoff(), 2));
        }

        if ($note = $request->ceo_note ?: $request->manager_note) {
            $mail->line('Note: ' . $note);
        }

        return $mail->action('View Request', url('/cash-advance-requests'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'cash_advance_request_id' => $this->advanceRequest->id,
            'url' => '/cash-advance-requests',
            'message' => $this->message,
        ];
    }
}
