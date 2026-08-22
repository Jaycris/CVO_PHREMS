<?php

namespace App\Notifications;

use App\Models\WorkFromHomeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Goes to whoever decides the request — the manager, or HR when there is none. */
class WorkFromHomeActionNeeded extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public WorkFromHomeRequest $request,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->employeeName();

        return (new MailMessage)
            ->subject("Work from home request - {$name}")
            ->line("{$name} has asked to work from home.")
            ->line('Days: ' . $this->request->dateLabel() . ' (' . $this->request->dayCount() . ' day(s))')
            ->line('Reason: ' . $this->request->reason)
            ->action('Review Request', url('/work-from-home'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'work_from_home_request_id' => $this->request->id,
            'url' => '/work-from-home',
            'message' => $this->employeeName() . ' asked to work from home on '
                . $this->request->dateLabel() . ' - awaiting your approval.',
        ];
    }

    protected function employeeName(): string
    {
        $employee = $this->request->employee;

        return $employee->fullName() ?: $employee->employee_id;
    }
}
