<?php

namespace App\Notifications;

use App\Models\OffsiteAssignment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Telling somebody they are not expected to clock in on these days.
 *
 * Without it they find out by worrying. Staff know a missing punch costs them a
 * day's pay, so an employee sent to an exhibit will either try to clock in from
 * a stand or spend the week wondering whether they are being marked absent.
 * Saying so plainly, in advance, is the point of this.
 *
 * Sent when the days are set, when they change, and when they are removed —
 * that last one matters most, because somebody who was told not to punch and is
 * then taken off the list must be told they need to punch again.
 */
class OffsiteWorkScheduled extends Notification implements ShouldQueue
{
    use Queueable;

    public const ADDED = 'added';

    public const CHANGED = 'changed';

    public const REMOVED = 'removed';

    public function __construct(
        public OffsiteAssignment $assignment,
        public string $change = self::ADDED,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $assignment = $this->assignment;

        return (new MailMessage)
            ->subject($this->subject())
            ->view('emails.offsite-work-scheduled', [
                'assignment' => $assignment,
                'change' => $this->change,
                'employeeName' => $assignment->employee?->first_name ?: 'there',
                'url' => url('/attendance'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'offsite_assignment_id' => $this->assignment->id,
            'url' => '/attendance',
            'message' => $this->change === self::REMOVED
                ? 'You must clock in as normal on ' . $this->assignment->rangeLabel() . ' — the off-site record was removed.'
                : 'No need to clock in on ' . $this->assignment->rangeLabel()
                    . ' (' . $this->assignment->reason . '). Those days are paid.',
        ];
    }

    protected function subject(): string
    {
        return match ($this->change) {
            self::REMOVED => 'You need to clock in on ' . $this->assignment->rangeLabel(),
            self::CHANGED => 'Your off-site work dates have changed',
            default => 'No need to clock in on ' . $this->assignment->rangeLabel(),
        };
    }
}
