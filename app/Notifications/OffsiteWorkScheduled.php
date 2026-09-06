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

        $mail = (new MailMessage)
            ->subject($this->subject())
            ->greeting('Hi ' . ($assignment->employee?->first_name ?: 'there') . ',');

        if ($this->change === self::REMOVED) {
            return $mail
                ->line('You are no longer recorded as working away from the office on '
                    . $assignment->rangeLabel() . '.')
                ->line('**Please clock in and out as normal on those days.** Without a time in they will be treated as absences.')
                ->line('If you think this is a mistake, speak to HR before those dates.');
        }

        return $mail
            ->line($this->change === self::CHANGED
                ? 'The dates for your off-site work have changed.'
                : 'You have been recorded as working away from the office.')
            ->line('**' . $assignment->rangeLabel() . '** — ' . $assignment->reason)
            ->line('You do not need to clock in or out on those days. They are paid as normal working days and you will not be marked absent for them.')
            ->line('Any rest day that falls inside those dates stays a rest day.')
            ->line('If any of this looks wrong, tell HR before those dates rather than after.');
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
