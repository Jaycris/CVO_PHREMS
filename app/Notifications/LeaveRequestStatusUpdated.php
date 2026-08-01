<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeaveRequestStatusUpdated extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public LeaveRequest $leaveRequest,
        public string $summary,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $leaveRequest = $this->leaveRequest;
        $employeeName = $leaveRequest->employee->fullName() ?: $leaveRequest->employee->employee_id;

        return (new MailMessage)
            ->subject("Leave request update — {$employeeName}")
            ->line("{$employeeName}'s leave request for {$leaveRequest->days_requested} day(s) of {$leaveRequest->leaveType->name} ({$leaveRequest->start_date->format('M d, Y')} – {$leaveRequest->end_date->format('M d, Y')}) has been updated.")
            ->line($this->summary)
            ->action('View Request', url("/leave-requests/{$leaveRequest->id}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'leave_request_id' => $this->leaveRequest->id,
            'message' => $this->summary,
        ];
    }
}
