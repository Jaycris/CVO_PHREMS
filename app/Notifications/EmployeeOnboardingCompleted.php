<?php

namespace App\Notifications;

use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Queued deliberately. Sending inline blocks the employee's submit request for
 * the full SMTP round-trip (~30s against a remote host), and because Laravel
 * dispatches channels in order the in-app notification would not appear until
 * the mail finished.
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

        return (new MailMessage)
            ->subject("Onboarding completed: {$name}")
            ->line("{$name} ({$this->employee->employee_id}) has submitted their onboarding form.")
            ->line('Review the details, then create their HRIS login when everything looks correct.')
            ->action('Review Employee', url("/employees/{$this->employee->id}"));
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
