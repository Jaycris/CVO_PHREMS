<?php

namespace Tests\Support;

use App\Services\Sms\SmsDriver;

/**
 * A driver that keeps what it was asked to send instead of sending it.
 *
 * Lets a test assert on the exact number and the exact characters that would
 * have left the building — which is the part worth checking, since the message
 * has to survive a 160-character limit and a character set that silently cuts
 * it to 70.
 */
class RecordingSmsDriver implements SmsDriver
{
    /** @var list<array{to: string, message: string}> */
    public array $sent = [];

    public function __construct(
        protected bool $configured = true,
        protected bool $accepts = true,
    ) {}

    public function name(): string
    {
        return 'recording';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function send(string $e164, string $message): bool
    {
        $this->sent[] = ['to' => $e164, 'message' => $message];

        return $this->accepts;
    }

    public function last(): ?array
    {
        return $this->sent === [] ? null : $this->sent[array_key_last($this->sent)];
    }

    public function count(): int
    {
        return count($this->sent);
    }
}
