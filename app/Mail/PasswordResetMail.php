<?php

namespace App\Mail;

use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells somebody HR has started a password reset for their account.
 *
 * Says who started it, on purpose. HR triggers this and never sees the
 * password, which is the whole point — but an employee receiving a reset link
 * they did not ask for needs to be able to tell the difference between their
 * own request and somebody else's, and to know who to ring if it was not them.
 */
class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Employee $employee,
        public string $url,
        public string $startedBy,
        public int $validForMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset your PHREMS password',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.password-reset',
        );
    }
}
