<?php

namespace App\Notifications;

use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Queued rather than sent inline: the SMTP round-trip runs on the request
 * thread otherwise, and because Laravel dispatches channels in order the
 * in-app notification would not appear until the mail finished.
 *
 * This does NOT require a worker locally — QUEUE_CONNECTION=sync in .env
 * makes queued work run immediately, while production uses the database
 * driver drained by the scheduler. Behaviour is chosen by environment,
 * not hard-coded here.
 */
class EmployeeOnboardingCompleted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Employee $employee) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->employee->fullName() ?: $this->employee->employee_id;
        $url = url("/employees/{$this->employee->id}");

        return (new MailMessage)
            ->subject("Onboarding completed: {$name}")
            ->view('emails.employee-onboarding-completed', [
                'employee' => $this->employee,
                'url' => $url,
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $name = $this->employee->fullName() ?: $this->employee->employee_id;

        return [
            'employee_id' => $this->employee->id,
            'url' => "/employees/{$this->employee->id}",
            'message' => "{$name} completed their onboarding form and is ready for login creation.",
        ];
    }
}
