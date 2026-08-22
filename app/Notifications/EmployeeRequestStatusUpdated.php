<?php

namespace App\Notifications;

use App\Models\EmployeeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Goes to the employee who filed it, so it links to their own page. Sending
 * them to an approval screen would be a 403 for anyone without the permission.
 */
class EmployeeRequestStatusUpdated extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public EmployeeRequest $request,
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
            $mail->line('Note: ' . $this->request->decision_note);
        }

        return $mail->action('View Request', url('/requests'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'employee_request_id' => $this->request->id,
            'url' => '/requests',
            'message' => $this->message,
        ];
    }
}
