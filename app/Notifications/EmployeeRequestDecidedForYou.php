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
        return (new MailMessage)
            ->subject('A request waiting on you was decided')
            ->view('emails.notice', [
                'heading' => 'A request waiting on you was decided',
                'heroLine' => 'It has left your queue, so here is what happened to it.',
                'lines' => [$this->message],
                'note' => $this->request->decision_note ? 'Note: ' . $this->request->decision_note : null,
                'actionLabel' => 'View Requests',
                'url' => url('/requests'),
            ]);
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
