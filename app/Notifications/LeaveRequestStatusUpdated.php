<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
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
        $url = url("/leave-requests/{$leaveRequest->id}");

        return (new MailMessage)
            ->subject("Leave request update - {$employeeName}")
            ->view('emails.leave-request-status-updated', [
                'leaveRequest' => $leaveRequest,
                'summary' => $this->summary,
                'url' => $url,
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'leave_request_id' => $this->leaveRequest->id,
            'message' => $this->summary,
        ];
    }
}
