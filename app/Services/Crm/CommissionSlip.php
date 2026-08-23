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

        // What the CRM says this agent's plan is called.
        public readonly ?string $scheme = null,

        /**
         * The rate bands behind that plan, as the CRM defines them: the
         * commission percentage that applies once the agent passes each share
         * of their target. Ordered lowest threshold first.
         *
         * Displayed, never applied. The CRM has already worked out what this
         * agent earned; re-deriving it here would be a second copy of the
         * maths to keep in step, and the two would eventually disagree.
         *
         * @var list<array{minimum_mtd_percent: float, commission_percent: float}>
         */
        public readonly array $schemeRules = [],

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

            scheme: self::schemeName($agent, $summary),
            schemeRules: self::schemeRules($agent, $summary),

            mtd: self::money($summary, ['mtd', 'mtd_amount']),
            target: self::money($summary, ['target', 'agent_target', 'quota'])
                ?? self::money($agent, ['agent_target', 'target']),
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

    /**
     * The scheme's name, however the CRM chose to wrap it.
     *
     * It sends an object — a name plus the rate bands — but a plain string is
     * just as reasonable a thing for it to send later, and the name is all
     * most of the app needs. Both are read rather than one being assumed.
     *
     * @param  array<string, mixed>  $agent
     * @param  array<string, mixed>  $summary
     */
    protected static function schemeName(array $agent, array $summary): ?string
    {
        $keys = ['commission_scheme', 'commissionScheme', 'scheme', 'service_profile', 'serviceProfile', 'profile'];

        foreach ([$agent, $summary] as $source) {
            foreach ($keys as $key) {
                $value = $source[$key] ?? null;

                if (is_array($value)) {
                    $name = self::text($value, ['name', 'title', 'label']);

                    if ($name !== null) {
                        return $name;
                    }

                    continue;
                }

                $name = self::text($source, [$key]);

                if ($name !== null) {
                    return $name;
                }
            }
        }

        return null;
    }

    /**
     * The rate bands, normalised and sorted by threshold.
     *
     * Sorted here rather than trusted, because "the band you are on" is read
     * off the first row whose threshold you have passed, and an out-of-order
     * list would quietly name the wrong one.
     *
     * @param  array<string, mixed>  $agent
     * @param  array<string, mixed>  $summary
     * @return list<array{minimum_mtd_percent: float, commission_percent: float}>
     */
    protected static function schemeRules(array $agent, array $summary): array
    {
        foreach ([$agent, $summary] as $source) {
            foreach (['commission_scheme', 'commissionScheme', 'scheme', 'service_profile'] as $key) {
                $scheme = $source[$key] ?? null;

                if (! is_array($scheme) || ! is_array($scheme['rules'] ?? null)) {
                    continue;
                }

                $rules = [];

                foreach ($scheme['rules'] as $rule) {
                    if (! is_array($rule)) {
                        continue;
                    }

                    $percent = self::money($rule, ['commission_percent', 'commissionPercent', 'percent']);

                    if ($percent === null) {
                        continue;
                    }

                    $rules[] = [
                        'minimum_mtd_percent' => self::money($rule, ['minimum_mtd_percent', 'minimumMtdPercent', 'min_mtd_percent']) ?? 0.0,
                        'commission_percent' => $percent,
                    ];
                }

                usort($rules, fn ($a, $b) => $a['minimum_mtd_percent'] <=> $b['minimum_mtd_percent']);

                return $rules;
            }
        }

        return [];
    }

    /**
     * The band this agent has actually reached, or null when it cannot be told.
     *
     * @return array{minimum_mtd_percent: float, commission_percent: float}|null
     */
    public function currentSchemeRule(): ?array
    {
        if ($this->schemeRules === [] || $this->mtdPercent === null) {
            return null;
        }

        $reached = null;

        foreach ($this->schemeRules as $rule) {
            if ($this->mtdPercent >= $rule['minimum_mtd_percent']) {
                $reached = $rule;
            }
        }

        return $reached;
    }

    /**
     * Whether the CRM's plan for this agent differs from what HR recorded.
     *
     * Silent when either side is missing. The CRM does not send the scheme
     * yet, and warning about every slip because of that would teach people to
     * ignore the warning before it ever meant anything.
     */
    public function schemeDisagreesWith(?string $recorded): bool
    {
        if ($this->scheme === null || blank($recorded)) {
            return false;
        }

        return mb_strtolower(trim($this->scheme)) !== mb_strtolower(trim($recorded));
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
