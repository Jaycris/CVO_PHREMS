<?php

namespace App\Services\Sms;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Everything that has to be true before a text message goes out.
 *
 * The drivers only know how to talk to a provider. This knows the rules: that
 * the feature is switched on, that the number is a real Philippine mobile, that
 * the message will survive the journey, and that none of it may ever throw.
 *
 * That last rule is the important one. SMS sits alongside email, never instead
 * of it, so a gateway being down must cost nothing — HR saving off-site days
 * cannot fail because a text could not be sent.
 */
class SmsGateway
{
    /**
     * One segment of a plain-text SMS.
     *
     * Longer messages still send, as two or three segments, and are charged as
     * two or three. Nothing here is worth paying twice for, so messages are
     * written to fit and cut if they do not.
     */
    public const SEGMENT = 160;

    /**
     * How a message names itself in its own opening words.
     *
     * The same as the sender name registered with the carriers, so the header
     * on the phone and the first word of the text agree. It is repeated in the
     * body on purpose: a sender name can fall back to a shared one while an
     * application is pending or if it is ever withdrawn, and a message that
     * says who it is from survives that.
     *
     * Changing this means changing the registered sender name too, which is
     * why it is one constant rather than a string typed into each notification.
     */
    public const SENDER = 'PhremsCVO';

    /** Settings keys, so a typo is a missing constant rather than silence. */
    public const OFFSITE_WORK = 'sms_offsite_work';

    public const URGENT_ANNOUNCEMENT = 'sms_urgent_announcement';

    public function __construct(protected SmsDriver $driver) {}

    public function driverName(): string
    {
        return $this->driver->name();
    }

    /** Whether messages would actually leave the building. */
    public function isLive(): bool
    {
        return $this->driver->isConfigured() && ! $this->driver instanceof LogDriver;
    }

    /**
     * Whether one kind of message is switched on.
     *
     * Read from settings rather than config so it can be turned off from the
     * Settings screen at the moment it is going wrong, without a deploy.
     */
    public static function enabledFor(string $feature): bool
    {
        return AppSetting::flag($feature, false);
    }

    /**
     * Sends one message, and never lets a failure escape.
     *
     * Returns whether the provider took it. A false here is worth nothing to
     * the caller beyond logging — there is no retry, because a text nobody
     * received an hour late is not worth the second credit.
     */
    public function send(?string $rawNumber, string $message): bool
    {
        $e164 = PhoneNumber::toE164($rawNumber);

        if ($e164 === null) {
            /*
             * Not an error. personal_contact_number has never been validated,
             * so a blank or a landline is ordinary and expected. It is logged
             * so somebody can go and fix the record, not so somebody is woken.
             */
            Log::info('SMS skipped — no usable mobile number on file.');

            return false;
        }

        $body = $this->compose($message);

        if ($body === '') {
            return false;
        }

        try {
            return $this->driver->send($e164, $body);
        } catch (Throwable $e) {
            // The drivers already promise not to throw. This is here because
            // "promise" is not "guarantee", and an SMS must never take a
            // payroll or attendance action down with it.
            Log::warning('SMS driver threw.', [
                'driver' => $this->driver->name(),
                'to' => PhoneNumber::mask($e164),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Makes a message safe and short enough to send as one segment.
     *
     * The subtle part is the character set. A plain SMS holds 160 GSM-7
     * characters, but a single character outside that set — an en dash, a curly
     * apostrophe, a peso sign — switches the whole message to UCS-2 and drops
     * the limit to 70. The app is full of en dashes: rangeLabel() renders
     * "Sep 8 – 13, 2026". One of those would silently triple the cost of every
     * off-site text, so they are converted rather than trusted.
     */
    public function compose(string $message): string
    {
        $ascii = strtr($message, [
            '–' => '-',
            '—' => '-',
            '‑' => '-',
            '’' => "'",
            '‘' => "'",
            '“' => '"',
            '”' => '"',
            '…' => '...',
            '•' => '-',
            '·' => '-',
            '₱' => 'PHP ',
            '€' => 'EUR ',
            "\u{00A0}" => ' ',
        ]);

        // Anything still outside printable ASCII would force UCS-2 too.
        $ascii = preg_replace('/[^\x20-\x7E]/u', '', $ascii);
        $ascii = trim(preg_replace('/\s+/', ' ', (string) $ascii));

        if (mb_strlen($ascii) <= self::SEGMENT) {
            return $ascii;
        }

        return rtrim(mb_substr($ascii, 0, self::SEGMENT - 3)) . '...';
    }
}
