<?php

namespace App\Notifications;

use App\Models\BankDetailRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Goes to whoever may approve bank detail changes.
 *
 * The account number is masked here too. A notification lands in an inbox and
 * sits there; the approver opens the app to see the change, and the whole
 * number is only ever on the employee's own record.
 */
class BankDetailActionNeeded extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public BankDetailRequest $request,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->employeeName();

        return (new MailMessage)
            ->subject("Bank detail change to review - {$name}")
            ->line("{$name} wants their salary paid to a different account.")
            ->line('From: ' . ($this->request->previous_bank_name ?: '—') . ' ' . $this->request->maskedPreviousAccount())
            ->line('To: ' . $this->request->bank_name . ' ' . $this->request->maskedAccount())
            ->line($this->request->reason ? 'Reason: ' . $this->request->reason : 'No reason given.')
            ->line('Check this against the employee before approving. This is where their salary lands.')
            ->action('Review Change', url('/bank-details'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'bank_detail_request_id' => $this->request->id,
            'url' => '/bank-details',
            'message' => $this->employeeName() . ' wants their salary paid to a different account - awaiting your approval.',
        ];
    }

    protected function employeeName(): string
    {
        $employee = $this->request->employee;

        return $employee->fullName() ?: $employee->employee_id;
    }
}
