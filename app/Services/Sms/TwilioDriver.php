<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Twilio, kept as the alternative.
 *
 * Written even though Semaphore was chosen, because it is the reason the driver
 * interface exists and an interface with one implementation proves nothing.
 * Twilio is the right answer if this ever needs to reach numbers outside the
 * Philippines — a US client, an overseas contractor — which Semaphore does not
 * do.
 *
 * No SDK. This is one HTTP POST, and the official package would drag its own
 * dependency tree onto shared hosting for it.
 */
class TwilioDriver implements SmsDriver
{
    public function __construct(
        protected ?string $accountSid,
        protected ?string $authToken,
        protected ?string $from,
        protected int $timeout = 15,
    ) {}

    public function name(): string
    {
        return 'twilio';
    }

    public function isConfigured(): bool
    {
        return filled($this->accountSid) && filled($this->authToken) && filled($this->from);
    }

    public function send(string $e164, string $message): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth($this->accountSid, $this->authToken)
                ->timeout($this->timeout)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$this->accountSid}/Messages.json", [
                    'To' => $e164,
                    'From' => $this->from,
                    'Body' => $message,
                ]);

            if ($response->successful()) {
                return true;
            }

            Log::warning('Twilio refused an SMS.', [
                'to' => PhoneNumber::mask($e164),
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (Throwable $e) {
            Log::warning('Twilio could not be reached.', [
                'to' => PhoneNumber::mask($e164),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
