<?php

namespace App\Notifications;

use App\Models\OvertimeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OvertimeRequestActionNeeded extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public OvertimeRequest $overtimeRequest,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->overtimeRequest;
        $employeeName = $request->employee->fullName() ?: $request->employee->employee_id;

        return (new MailMessage)
            ->subject("Overtime request awaiting your approval - {$employeeName}")
            ->view('emails.overtime-request-action-needed', [
                'overtimeRequest' => $request,
                'url' => url("/overtime/{$request->id}"),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $request = $this->overtimeRequest;
        $employeeName = $request->employee->fullName() ?: $request->employee->employee_id;

        return [
            'overtime_request_id' => $request->id,
            'url' => "/overtime/{$request->id}",
            'message' => "{$employeeName} filed {$request->hours_requested} hour(s) of overtime on "
                . $request->work_date->format('M d, Y') . ' - awaiting your approval.',
        ];
    }
}
