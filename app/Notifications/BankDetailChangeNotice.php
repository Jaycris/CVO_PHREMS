<?php

namespace App\Notifications;

use App\Models\BankDetailRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * For the people who need to know, not the person who has to decide.
 *
 * HR and Accounting care that the account payroll pays into has moved — HR
 * because it is a change to an employee record, Accounting because it changes
 * where money lands. Neither of them approves it, so this deliberately does
 * not ask them to: an alert telling someone to act on something they have no
 * permission for is how people learn to ignore the bell.
 */
class BankDetailChangeNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public BankDetailRequest $request,
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
        $name = $this->request->employee?->fullName() ?? 'An employee';

        $mail = (new MailMessage)
            ->subject($this->subject)
            ->line($this->message)
            ->line('From: ' . ($this->request->previous_bank_name ?: '—') . ' ' . $this->request->maskedPreviousAccount())
            ->line('To: ' . $this->request->bank_name . ' ' . $this->request->maskedAccount());

        if ($this->request->reason) {
            $mail->line('Reason given: ' . $this->request->reason);
        }

        if ($this->request->decision_note) {
            $mail->line('Note from the approver: ' . $this->request->decision_note);
        }

        return $mail
            ->line('This is for your records — no action is needed from you.')
            ->action('View Bank Details', url('/bank-details'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'bank_detail_request_id' => $this->request->id,
            'url' => '/bank-details',
            // The bell reads data.message, so this key is not optional.
            'message' => $this->message,
        ];
    }
}
