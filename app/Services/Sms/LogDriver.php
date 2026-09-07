<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Writes the message to the log instead of sending it.
 *
 * The default, and deliberately so. An unconfigured app must not be one
 * SEMAPHORE_API_KEY away from texting the whole company, and local development
 * must never reach a real phone — the test data has real colleagues' numbers in
 * it. Seeing the exact message in laravel.log is also the fastest way to check
 * wording and length without spending a credit.
 */
class LogDriver implements SmsDriver
{
    public function name(): string
    {
        return 'log';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(string $e164, string $message): bool
    {
        Log::info('SMS (not sent — log driver)', [
            'to' => PhoneNumber::mask($e164),
            'characters' => mb_strlen($message),
            'message' => $message,
        ]);

        return true;
    }
}
