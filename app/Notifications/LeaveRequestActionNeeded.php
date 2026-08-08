<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeaveRequestActionNeeded extends Notification implements ShouldQueue
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
        $url = url("/leave-requests/{$leaveRequest->id}");

        return (new MailMessage)
            ->subject("Leave request awaiting your approval - {$employeeName}")
            ->view('emails.leave-request-action-needed', [
                'leaveRequest' => $leaveRequest,
                'url' => $url,
            ]);
    }

    public function toArray(object $notifiable): array
    {
        $leaveRequest = $this->leaveRequest;
        $employeeName = $leaveRequest->employee->fullName() ?: $leaveRequest->employee->employee_id;

        return [
            'leave_request_id' => $leaveRequest->id,
            'message' => "{$employeeName} requested {$leaveRequest->days_requested} day(s) of {$leaveRequest->leaveType->name} - awaiting your approval.",
        ];
    }
}
