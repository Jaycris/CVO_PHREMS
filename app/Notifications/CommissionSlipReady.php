<?php

namespace App\Notifications;

use App\Models\CommissionSlip;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an agent their commission slip is available.
 *
 * Queued, because a hundred of these go out on one button press and sending
 * them inline would time the request out long before the last one.
 *
 * The net figure is in the mail so the agent knows what to expect without
 * opening anything; the statement behind it lives on the page.
 */
class CommissionSlipReady extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public CommissionSlip $slip,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $month = $this->slip->monthLabel();
        $url = url('/my-commission');

        return (new MailMessage)
            ->subject("Your commission slip for {$month}")
            ->view('emails.commission-slip-ready', [
                'slip' => $this->slip,
                'url' => $url,
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'commission_slip_id' => $this->slip->id,
            'url' => '/my-commission',
            'message' => 'Your commission slip for ' . $this->slip->monthLabel() . ' is ready to view.',
        ];
    }
}
