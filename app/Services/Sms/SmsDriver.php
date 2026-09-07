<?php

namespace App\Services\Sms;

/**
 * One SMS provider.
 *
 * The interface exists because the provider was undecided while this was being
 * built, and because the first choice is rarely the last one — a gateway that
 * cannot get a sender name approved, or puts prices up, is swapped by writing
 * one class rather than by hunting through the notifications.
 *
 * Implementations receive an E.164 number and are responsible for reshaping it
 * into whatever their own API wants.
 */
interface SmsDriver
{
    /**
     * Hands one message to the provider.
     *
     * Returns whether the provider accepted it — which is not the same as the
     * phone receiving it. Nothing here can promise delivery; a carrier may
     * still drop the message minutes later.
     *
     * Must not throw. A gateway being down is not a reason for an employee's
     * off-site days to fail to save.
     */
    public function send(string $e164, string $message): bool;

    /** Whether there are enough credentials to try. */
    public function isConfigured(): bool;

    /** For logs and the settings screen. */
    public function name(): string;
}
