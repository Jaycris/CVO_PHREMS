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

    /**
     * The manager who already approved it, when this is the second stage.
     *
     * The CEO or COO hear about a request twice in its life — when it is filed
     * with nobody in between, and after a manager has approved it. Saying which
     * of the two this is saves opening the request to find out.
     */
    protected function approvedByManager(): ?string
    {
        $leaveRequest = $this->leaveRequest;

        if ($leaveRequest->manager_decision !== 'approved') {
            return null;
        }

        return $leaveRequest->manager?->fullName() ?: 'their manager';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $leaveRequest = $this->leaveRequest;
        $employeeName = $leaveRequest->employee->fullName() ?: $leaveRequest->employee->employee_id;
        $url = url("/leave-requests/{$leaveRequest->id}");
        $manager = $this->approvedByManager();

        return (new MailMessage)
            ->subject($manager
                ? "Approved by {$manager}, awaiting your approval - {$employeeName}"
                : "Leave request awaiting your approval - {$employeeName}")
            ->view('emails.leave-request-action-needed', [
                'leaveRequest' => $leaveRequest,
                'url' => $url,
            ]);
    }

    public function toArray(object $notifiable): array
    {
        $leaveRequest = $this->leaveRequest;
        $employeeName = $leaveRequest->employee->fullName() ?: $leaveRequest->employee->employee_id;

        $days = "{$leaveRequest->days_requested} day(s) of {$leaveRequest->leaveType->name}";
        $manager = $this->approvedByManager();

        return [
            'leave_request_id' => $leaveRequest->id,
            'message' => $manager
                ? "{$manager} approved {$employeeName}'s {$days} - awaiting your approval."
                : "{$employeeName} requested {$days} - awaiting your approval.",
        ];
    }
}
