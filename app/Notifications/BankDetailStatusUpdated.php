<?php

namespace App\Notifications;

use App\Models\BankDetailRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Goes to the employee whose details these are, so it links to My Profile.
 * Sending them to the approval screen would be a 403 — that page is gated on a
 * permission an employee does not hold.
 *
 * This one is worth sending even when the answer is no: an employee who did not
 * file the request needs to hear that someone tried.
 */
class BankDetailStatusUpdated extends Notification implements ShouldQueue
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
        $mail = (new MailMessage)
            ->subject($this->subject)
            ->line($this->message);

        if ($this->request->decision_note) {
            $mail->line('Note from HR: ' . $this->request->decision_note);
        }

        return $mail
            ->line('If this was not you, tell HR straight away.')
            ->action('View My Profile', url('/my-profile'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'bank_detail_request_id' => $this->request->id,
            'url' => '/my-profile',
            'message' => $this->message,
        ];
    }
}
