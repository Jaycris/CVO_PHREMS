<?php

namespace App\Notifications;

use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent immediately so the Phase 1 onboarding flow works without requiring a
 * separate queue worker during local testing.
 */
class EmployeeOnboardingCompleted extends Notification
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
