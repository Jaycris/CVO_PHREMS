<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeaveRequestActionNeeded extends Notification
{
    use Queueable;

    public function __construct(
        public LeaveRequest $leaveRequest,
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
            ->subject("Leave request awaiting your approval — {$employeeName}")
            ->line("{$employeeName} requested {$leaveRequest->days_requested} day(s) of {$leaveRequest->leaveType->name} ({$leaveRequest->start_date->format('M d, Y')} – {$leaveRequest->end_date->format('M d, Y')}).")
            ->when($leaveRequest->is_lwop, fn ($mail) => $mail->line('Note: this employee does not have sufficient leave credits — this request would be Leave Without Pay (LWOP) if approved.'))
            ->action('Review Request', url("/leave-requests/{$leaveRequest->id}"));
    }

    public function toArray(object $notifiable): array
    {
        $leaveRequest = $this->leaveRequest;

        return [
            'leave_request_id' => $leaveRequest->id,
            'message' => ($leaveRequest->employee->fullName() ?: $leaveRequest->employee->employee_id)
                . " requested {$leaveRequest->days_requested} day(s) of {$leaveRequest->leaveType->name} — awaiting your approval.",
        ];
    }
}
