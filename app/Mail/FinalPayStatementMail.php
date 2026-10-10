<?php

namespace App\Mail;

use App\Models\FinalPay;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The settlement, sent to the address they will still be able to read.
 *
 * Their company mailbox and their PHREMS login are both gone by the time this
 * is paid, so unlike a payslip this carries the figures in the email itself.
 * There is nowhere for them to go and look them up.
 */
class FinalPayStatementMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public FinalPay $finalPay) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your final pay from CreatiVision Outsourcing',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.final-pay-statement',
            with: ['finalPay' => $this->finalPay, 'employee' => $this->finalPay->employee],
        );
    }
}
