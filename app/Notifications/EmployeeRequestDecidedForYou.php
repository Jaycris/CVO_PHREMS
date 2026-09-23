<?php

namespace App\Notifications;

use App\Models\EmployeeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a manager that a request waiting on them was decided by the CEO or COO.
 *
 * Without it the request simply disappears from their queue, and the first they
 * hear of it is the employee working from home on a day they never approved.
 */
class EmployeeRequestDecidedForYou extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public EmployeeRequest $request,
        public string $message,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('A request waiting on you was decided')
            ->line($this->message);

        if ($this->request->decision_note) {
            $mail->line('Note: ' . $this->request->decision_note);
        }

        return $mail->action('View Requests', url('/requests'));
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
