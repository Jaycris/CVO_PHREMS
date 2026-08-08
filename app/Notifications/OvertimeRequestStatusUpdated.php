<?php

namespace App\Notifications;

use App\Models\OvertimeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OvertimeRequestStatusUpdated extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public OvertimeRequest $overtimeRequest,
        public string $summary,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->overtimeRequest;

        return (new MailMessage)
            ->subject('Your overtime request was ' . ($request->status === 'approved' ? 'approved' : 'declined'))
            ->view('emails.overtime-request-status-updated', [
                'overtimeRequest' => $request,
                'summary' => $this->summary,
                'url' => url("/overtime/{$request->id}"),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $request = $this->overtimeRequest;

        return [
            'overtime_request_id' => $request->id,
            'url' => "/overtime/{$request->id}",
            'message' => 'Your overtime on ' . $request->work_date->format('M d, Y') . " was {$this->summary}.",
        ];
    }
}
