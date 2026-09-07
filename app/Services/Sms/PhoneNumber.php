<?php

namespace App\Services\Sms;

/**
 * Turning what somebody typed into a number a gateway will accept.
 *
 * personal_contact_number is free text — employees fill it in themselves during
 * onboarding and there has never been a format check. The same phone arrives as
 * "09171234567", "0917 123 4567", "+63 917 123 4567" and "9171234567", and a
 * gateway accepts exactly one of those.
 *
 * Mobiles only. Every Philippine mobile is 9XX; a landline cannot receive an
 * SMS, so sending to one burns a credit to reach nobody. Anything that is not
 * recognisably a PH mobile comes back null and is skipped rather than guessed
 * at — a wrong number is worse than no number, because somebody outside the
 * company then gets an employee's roster.
 */
class PhoneNumber
{
    /**
     * E.164 for a Philippine mobile, or null if it is not one.
     *
     * E.164 is the canonical form here. Each driver reformats from it as its
     * own provider wants, so the awkward cases are reasoned about once.
     */
    public static function toE164(?string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $raw);

        if ($digits === '') {
            return null;
        }

        // 00 is the international access prefix in much of the world and gets
        // typed by people who have dialled abroad from a landline.
        if (str_starts_with($digits, '0063')) {
            $digits = substr($digits, 2);
        }

        $national = match (true) {
            // 639171234567 — already international.
            strlen($digits) === 12 && str_starts_with($digits, '639') => substr($digits, 2),

            // 09171234567 — how it is written in the Philippines.
            strlen($digits) === 11 && str_starts_with($digits, '09') => substr($digits, 1),

            // 9171234567 — the leading zero left off.
            strlen($digits) === 10 && str_starts_with($digits, '9') => $digits,

            default => null,
        };

        return $national === null ? null : '+63' . $national;
    }

    public static function isSendable(?string $raw): bool
    {
        return static::toE164($raw) !== null;
    }

    /** Digits only, no plus — the form some gateways want. */
    public static function toDigits(?string $raw): ?string
    {
        $e164 = static::toE164($raw);

        return $e164 === null ? null : ltrim($e164, '+');
    }

    /**
     * Safe to show in a log or on screen: 0917•••4567.
     *
     * Enough to recognise which phone it was without writing a staff member's
     * mobile number into a log file that other people read.
     */
    public static function mask(?string $raw): string
    {
        $e164 = static::toE164($raw);

        if ($e164 === null) {
            return 'unusable';
        }

        $national = '0' . substr($e164, 3);

        return substr($national, 0, 4) . '•••' . substr($national, -4);
    }
}
