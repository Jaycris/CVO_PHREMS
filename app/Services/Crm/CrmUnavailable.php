<?php

namespace App\Services\Crm;

use RuntimeException;

/**
 * The CRM could not answer.
 *
 * Carries a sentence fit to show an agent, because a commission page that dies
 * with a stack trace tells them nothing and a page that silently shows zero
 * tells them something false.
 */
class CrmUnavailable extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self('The CRM connection has not been set up yet. Ask IT to fill in CRM_API_BASE_URL and CRM_HRIS_API_TOKEN.');
    }

    public static function unreachable(string $detail): self
    {
        return new self('Could not reach the CRM. ' . $detail);
    }

    public static function rejected(int $status): self
    {
        return match (true) {
            $status === 401 || $status === 403 => new self('The CRM refused this app\'s token. Ask IT to check CRM_HRIS_API_TOKEN.', $status),
            $status === 404 => new self('The CRM has no commission record for this agent and month.', $status),
            $status >= 500 => new self('The CRM had an error answering. Try again shortly.', $status),
            default => new self("The CRM rejected the request (HTTP {$status}).", $status),
        };
    }

    public static function malformed(string $detail): self
    {
        return new self('The CRM replied with something this app could not read: ' . $detail);
    }
}
