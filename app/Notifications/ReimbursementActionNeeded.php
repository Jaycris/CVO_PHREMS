<?php

namespace App\Notifications;

use App\Models\ReimbursementRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReimbursementActionNeeded extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ReimbursementRequest $claim,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $claim = $this->claim;
        $name = $claim->employee->fullName() ?: $claim->employee->employee_id;

        return (new MailMessage)
            ->subject("Reimbursement claim to review - {$name}")
            ->line("{$name} is claiming back PHP " . number_format((float) $claim->amount_requested, 2) . '.')
            ->line('For: ' . $claim->categoryLabel() . ' on ' . $claim->expense_date->format('M j, Y'))
            ->line($claim->description)
            ->action('Review Claim', url('/reimbursements'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $claim = $this->claim;
        $name = $claim->employee->fullName() ?: $claim->employee->employee_id;

        return [
            'reimbursement_request_id' => $claim->id,
            'url' => '/reimbursements',
            'message' => "{$name} is claiming back PHP "
                . number_format((float) $claim->amount_requested, 2) . ' - awaiting your approval.',
        ];
    }
}
