<?php

namespace App\Notifications;

use App\Models\Announcement;
use App\Notifications\Channels\SmsChannel;
use App\Services\Sms\SmsGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Putting a notice in front of somebody rather than waiting for them to look.
 *
 * Sent only when whoever wrote it asks for it. A noticeboard that emails
 * everybody about everything is a noticeboard people filter to a folder, and
 * then the one notice that mattered goes unread with the rest. The dashboard
 * board is the default; this is the exception for something nobody can afford
 * to miss.
 */
class AnnouncementPosted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Announcement $announcement,
        public bool $bySms = false,
    ) {}

    /**
     * A text only when somebody asked for one, on this notice.
     *
     * Two switches still have to agree, but the second is now a decision made
     * per notice rather than inferred from the Important label. Inferring it
     * was worse: a kind is set once out of habit, whereas a box ticked while
     * looking at the cost is a choice somebody actually made. Ordinary news
     * reaching people by text is how a company teaches its staff to ignore its
     * texts, and then the office-is-closed message goes unread with the rest.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['mail', 'database'];

        if ($this->bySms && SmsGateway::enabledFor(SmsGateway::URGENT_ANNOUNCEMENT)) {
            $channels[] = SmsChannel::class;
        }

        return $channels;
    }

    /**
     * The notice itself, not a pointer to it.
     *
     * No link, because Philippine carriers strip URLs from business SMS. The
     * gateway cuts this to one segment, so the title carries the message and
     * the body only adds to it if there is room.
     */
    public function toSms(object $notifiable): string
    {
        return SmsGateway::SENDER . ': ' . $this->announcement->title . '. ' . $this->announcement->body;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->announcement->title)
            ->view('emails.announcement-posted', [
                'announcement' => $this->announcement,
                'url' => url('/announcements'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'announcement_id' => $this->announcement->id,
            'url' => '/announcements',
            'message' => $this->announcement->title,
        ];
    }
}
