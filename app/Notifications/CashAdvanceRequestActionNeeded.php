<?php

namespace App\Notifications;

use App\Models\CashAdvanceRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CashAdvanceRequestActionNeeded extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public CashAdvanceRequest $advanceRequest,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->advanceRequest;
        $name = $request->employee->fullName() ?: $request->employee->employee_id;
        $amount = number_format($request->effectiveAmount(), 2);

        return (new MailMessage)
            ->subject("Cash advance awaiting your approval - {$name}")
            ->line("{$name} requested a cash advance of PHP {$amount}.")
            ->line('Deduction: ' . $request->deductionPlanLabel())
            ->line('Reason: ' . $request->reason)
            ->action('Review Request', url('/cash-advance-requests'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $request = $this->advanceRequest;
        $name = $request->employee->fullName() ?: $request->employee->employee_id;

        return [
            'cash_advance_request_id' => $request->id,
            'url' => '/cash-advance-requests',
            'message' => "{$name} requested a cash advance of PHP "
                . number_format($request->effectiveAmount(), 2) . ' - awaiting your approval.',
        ];
    }
}
