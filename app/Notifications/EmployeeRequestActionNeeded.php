<?php

namespace App\Notifications;

use App\Models\EmployeeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Goes to whoever decides it — the manager, or HR when there is none. */
class EmployeeRequestActionNeeded extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public EmployeeRequest $request,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->employeeName();
        $type = $this->request->typeName();

        return (new MailMessage)
            ->subject("{$type} request - {$name}")
            ->view('emails.notice', [
                'heading' => $type . ' request needs approval',
                'heroLine' => 'A team member is waiting for your review.',
                'lines' => [
                    "{$name} has filed a {$type} request.",
                    $this->request->days->isNotEmpty()
                        ? 'Days: ' . $this->request->dateLabel() . ' (' . $this->request->dayCount() . ' day(s))'
                        : null,
                    'Details: ' . $this->request->details,
                ],
                'actionLabel' => 'Review Request',
                'url' => url('/requests'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $when = $this->request->days->isNotEmpty()
            ? ' for ' . $this->request->dateLabel()
            : '';

        return [
            'employee_request_id' => $this->request->id,
            'url' => '/requests',
            'message' => $this->employeeName() . ' filed a ' . strtolower($this->request->typeName())
                . ' request' . $when . ' - awaiting your approval.',
        ];
    }

    protected function employeeName(): string
    {
        $employee = $this->request->employee;

        return $employee->fullName() ?: $employee->employee_id;
    }
}
