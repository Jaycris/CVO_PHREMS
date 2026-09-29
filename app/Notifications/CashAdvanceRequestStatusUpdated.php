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

        $approved = $request->status === 'approved';

        return (new MailMessage)
            ->subject($this->subject)
            ->view('emails.notice', [
                'heading' => 'Your cash advance was decided',
                'lines' => [
                    $this->message,
                    $approved ? 'Amount: PHP ' . number_format($request->effectiveAmount(), 2) : null,
                    $approved
                        ? 'Deduction: ' . $request->deductionPlanLabel()
                            . ' (PHP ' . number_format($request->perCutoffAmount(), 2) . ' per cutoff)'
                        : null,
                ],
                'note' => $request->decision_note ? 'Note: ' . $request->decision_note : null,
                'actionLabel' => 'View Request',
                'url' => url('/cash-advance-requests'),
            ]);
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
