<?php

namespace App\Services\Crm;

use Illuminate\Support\Collection;

/**
 * An agent's commission slip for one month, as the CRM reported it.
 *
 * Nothing here is calculated. The CRM works out MTD, the percentages, the
 * exchange rate, the card hold and the net; this object carries those numbers
 * to the screen unchanged. If a figure is absent it stays absent — see
 * CommissionTransaction for why a dash beats a zero.
 */
class CommissionSlip
{
    /** @param Collection<int, CommissionTransaction> $transactions */
    public function __construct(
        public readonly string $month,
        public readonly ?string $agentName = null,
        public readonly ?string $team = null,
        public readonly ?string $workType = null,
        public readonly ?float $mtd = null,
        public readonly ?float $target = null,
        public readonly ?float $mtdPercent = null,
        public readonly ?float $serviceCommission = null,
        public readonly ?float $markupCommission = null,
        public readonly ?float $usdTotal = null,
        public readonly ?float $exchangeRate = null,
        public readonly ?float $phpTotal = null,
        public readonly ?float $cardHoldPercent = null,
        public readonly ?float $cardHoldAmount = null,
        public readonly ?float $netCommission = null,
        public readonly Collection $transactions = new Collection,
        public readonly bool $statementSupplied = true,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromCrm(array $payload, string $month): self
    {
        $agent = is_array($payload['agent'] ?? null) ? $payload['agent'] : [];
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : $payload;

        // A missing "transactions" key and an empty one mean different things:
        // the first is a CRM that does not send the statement yet, the second
        // is an agent with no sales. The page says so either way.
        $hasStatement = array_key_exists('transactions', $payload) && is_array($payload['transactions']);

        $rows = collect($hasStatement ? $payload['transactions'] : [])
            ->filter(fn ($row) => is_array($row))
            ->map(fn (array $row) => CommissionTransaction::fromCrm($row))
            ->values();

        return new self(
            month: self::text($payload, ['month']) ?? $month,
            agentName: self::text($agent, ['name', 'full_name', 'agent_name'])
                ?? self::text($payload, ['agent_name']),
            team: self::text($agent, ['team']) ?? self::text($summary, ['team']),
            workType: self::text($agent, ['work_type', 'workType']) ?? self::text($summary, ['work_type']),
            mtd: self::money($summary, ['mtd', 'mtd_amount']),
            target: self::money($summary, ['target', 'quota']),
            mtdPercent: self::money($summary, ['mtd_percent', 'mtdPercent', 'mtd_percentage']),
            serviceCommission: self::money($summary, ['service_commission', 'serviceCommission']),
            markupCommission: self::money($summary, ['markup_commission', 'markupCommission']),
            usdTotal: self::money($summary, ['usd_total', 'usdTotal', 'total_usd']),
            exchangeRate: self::money($summary, ['exchange_rate', 'exchangeRate', 'fx_rate']),
            phpTotal: self::money($summary, ['php_total', 'phpTotal', 'total_php']),
            cardHoldPercent: self::money($summary, ['card_payment_hold_percent', 'card_hold_percent', 'cardHoldPercent']),
            cardHoldAmount: self::money($summary, ['card_payment_hold_amount', 'card_hold_amount', 'cardHoldAmount']),
            netCommission: self::money($summary, ['net_commission', 'netCommission']),
            transactions: $rows,
            statementSupplied: $hasStatement,
        );
    }

    public function monthLabel(): string
    {
        try {
            return \Illuminate\Support\Carbon::createFromFormat('Y-m', $this->month)->format('F Y');
        } catch (\Throwable) {
            return $this->month;
        }
    }

    /** @param array<string, mixed> $data @param list<string> $keys */
    protected static function text(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                return (string) $data[$key];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $data @param list<string> $keys */
    protected static function money(array $data, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
                continue;
            }

            $value = is_string($data[$key]) ? str_replace([',', ' ', '%'], '', $data[$key]) : $data[$key];

            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }
}
