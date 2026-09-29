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
            ->view('emails.notice', [
                'heading' => 'Payout account change to review',
                'heroLine' => 'Somebody wants their salary paid somewhere else.',
                'lines' => [
                    "{$name} wants their salary paid to a different account.",
                    'From: ' . ($this->request->previous_bank_name ?: '—') . ' ' . $this->request->maskedPreviousAccount(),
                    'To: ' . $this->request->bank_name . ' ' . $this->request->maskedAccount(),
                    $this->request->reason ? 'Reason: ' . $this->request->reason : 'No reason given.',
                ],
                // The one thing not to skim past: this is the classic payroll
                // fraud, and an email is exactly how it arrives.
                'note' => 'Check this against the employee in person before approving. This is where their salary lands.',
                'actionLabel' => 'Review Change',
                'url' => url('/bank-details'),
            ]);
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
