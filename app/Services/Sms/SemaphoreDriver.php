<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Semaphore — a Philippine SMS gateway.
 *
 * Chosen over an international provider because everything about this company
 * is Philippine: the staff, the numbers, the billing. A local gateway connects
 * to Globe, Smart and DITO directly rather than routing in from abroad, bills
 * in pesos, and registers the sender name with the carriers on your behalf.
 *
 * The sender name is not decoration. Without a registered one the message
 * arrives from a number nobody recognises, and a country that has spent years
 * being flooded with text scams has learned to ignore exactly that.
 */
class SemaphoreDriver implements SmsDriver
{
    public function __construct(
        protected ?string $apiKey,
        protected ?string $senderName,
        protected string $endpoint = 'https://api.semaphore.co/api/v4/messages',
        protected int $timeout = 15,
    ) {}

    public function name(): string
    {
        return 'semaphore';
    }

    public function isConfigured(): bool
    {
        return filled($this->apiKey);
    }

    public function send(string $e164, string $message): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        // Semaphore wants the national form — 09171234567 — not E.164.
        $number = '0' . substr($e164, 3);

        try {
            $response = Http::asForm()
                ->timeout($this->timeout)
                ->post($this->endpoint, array_filter([
                    'apikey' => $this->apiKey,
                    'number' => $number,
                    'message' => $message,
                    // Omitted when unset, which makes Semaphore fall back to
                    // its own shared sender rather than rejecting the send.
                    'sendername' => $this->senderName,
                ]));

            if ($response->successful()) {
                return true;
            }

            Log::warning('Semaphore refused an SMS.', [
                'to' => PhoneNumber::mask($e164),
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (Throwable $e) {
            Log::warning('Semaphore could not be reached.', [
                'to' => PhoneNumber::mask($e164),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
