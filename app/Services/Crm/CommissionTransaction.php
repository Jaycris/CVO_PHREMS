<?php

namespace App\Services\Crm;

/**
 * One line of an agent's commission statement, exactly as the CRM sent it.
 *
 * Every money field is nullable and nothing is derived. A missing field renders
 * as a dash rather than a zero, because zero is a claim — it says the CRM
 * calculated nothing owed — and a dash only says this app was not told.
 */
class CommissionTransaction
{
    public function __construct(
        public readonly ?string $soldDate = null,
        public readonly ?string $brand = null,
        public readonly ?string $client = null,
        public readonly ?string $bookTitle = null,
        public readonly ?string $service = null,
        public readonly ?string $paymentMethod = null,
        public readonly ?float $saleAmount = null,
        public readonly ?float $serviceAmount = null,
        public readonly ?float $markupAmount = null,

        // How much of this sale the threshold took off before commission was
        // worked out. The CRM applies it and sends the result already reduced,
        // so this exists purely so the slip can show its working.
        public readonly ?float $thresholdApplied = null,

        public readonly ?float $serviceCommission = null,
        public readonly ?float $markupCommission = null,
        public readonly ?float $usdTotal = null,
        public readonly ?float $phpTotal = null,
        public readonly ?float $cardHoldAmount = null,
        public readonly ?float $netCommission = null,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromCrm(array $row): self
    {
        return new self(
            soldDate: self::text($row, ['sold_date', 'soldDate', 'date']),
            brand: self::text($row, ['brand']),
            client: self::text($row, ['author', 'client', 'author_client', 'customer']),
            bookTitle: self::text($row, ['book_title', 'bookTitle', 'title']),
            service: self::text($row, ['service', 'service_name']),
            paymentMethod: self::text($row, ['payment_method', 'paymentMethod']),
            saleAmount: self::money($row, ['sale_amount', 'saleAmount', 'amount']),
            serviceAmount: self::money($row, ['service_amount', 'service_mtd', 'serviceAmount']),
            markupAmount: self::money($row, ['markup_amount', 'markup_mtd', 'markupAmount']),
            thresholdApplied: self::money($row, ['threshold_applied_amount', 'thresholdAppliedAmount']),
            serviceCommission: self::money($row, ['service_commission', 'serviceCommission']),
            markupCommission: self::money($row, ['markup_commission', 'markupCommission']),
            usdTotal: self::money($row, ['usd_total', 'usdTotal', 'total_usd']),
            phpTotal: self::money($row, ['php_total', 'phpTotal', 'total_php']),
            cardHoldAmount: self::money($row, ['card_hold_amount', 'cardHoldAmount', 'card_payment_hold_amount']),
            netCommission: self::money($row, ['net_commission', 'netCommission']),
        );
    }

    /** Card hold only ever applies to card payments, and the CRM decides that. */
    public function wasHeld(): bool
    {
        return $this->cardHoldAmount !== null && $this->cardHoldAmount > 0;
    }

    /**
     * Reads the first key the CRM actually sent.
     *
     * The aliases are here so a naming difference between the two systems is a
     * shrug rather than a column of dashes; the first name in each list is the
     * one documented in docs/crm-commission-api.md.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    protected static function text(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && $row[$key] !== '') {
                return (string) $row[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    protected static function money(array $row, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
                continue;
            }

            // The CRM may send "1,234.56" or 1234.56; both mean the same thing.
            $value = is_string($row[$key]) ? str_replace([',', ' '], '', $row[$key]) : $row[$key];

            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }
}
