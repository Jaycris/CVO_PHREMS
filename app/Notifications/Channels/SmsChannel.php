<?php

namespace App\Notifications\Channels;

use App\Services\Sms\SmsGateway;
use Illuminate\Notifications\Notification;

/**
 * The SMS delivery channel for Laravel's notification system.
 *
 * Listed in a notification's via() alongside 'mail' and 'database', never
 * instead of them. A notification that reaches somebody only by text is one
 * they cannot go back and re-read, and there is no record of it in the app.
 *
 * A notification opts in by writing a toSms() method. One without it is quietly
 * skipped rather than erroring, so this channel can be added to a via() list
 * without every notification having to answer for it.
 */
class SmsChannel
{
    public function __construct(protected SmsGateway $gateway) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toSms')) {
            return;
        }

        $number = $notifiable->routeNotificationFor('sms', $notification);

        if (blank($number)) {
            return;
        }

        $message = (string) $notification->toSms($notifiable);

        if (trim($message) === '') {
            return;
        }

        // The gateway swallows its own failures. Nothing is returned because
        // there is nothing useful a caller could do about it.
        $this->gateway->send($number, $message);
    }
}
