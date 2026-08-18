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

    /**
     * Nobody has confirmed which CRM account this person is.
     *
     * Deliberately a dead end rather than a guess: the CRM keys agents by its
     * own names and aliases, and matching on a hunch risks showing one agent
     * another agent's earnings.
     */
    public static function notLinked(): self
    {
        return new self('This employee is not linked to a CRM account yet. HR can link them on the Commission Slips screen.');
    }

    /**
     * The CRM answered about a different employee than the one asked about.
     *
     * Nothing is shown. A mismatch here means the link and the CRM disagree,
     * and the wrong answer is not "probably fine" — it is one agent's earnings
     * on another agent's screen.
     */
    public static function wrongEmployee(string $claimed, string $expected): self
    {
        return new self(
            "The CRM returned commission data for employee {$claimed}, not {$expected}. "
            . 'Nothing is shown until that is sorted out. Ask HR to re-check the CRM link for this employee.'
        );
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
