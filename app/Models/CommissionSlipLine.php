<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the transaction statement, as the CRM reported it at compute time.
 *
 * Every amount is nullable and nothing is derived. A field the CRM did not send
 * stays null and prints as a dash — see the note on commission_slips for why a
 * zero would be worse than a blank.
 */
class CommissionSlipLine extends Model
{
    protected $fillable = [
        'commission_slip_id', 'sort_order',
        'sold_date', 'brand', 'client', 'book_title', 'service', 'payment_method',
        'sale_amount', 'service_amount', 'markup_amount',
        'service_commission', 'markup_commission',
        'usd_total', 'php_total', 'card_hold_amount', 'net_commission',
    ];

    protected function casts(): array
    {
        return [
            'sale_amount' => 'decimal:2',
            'service_amount' => 'decimal:2',
            'markup_amount' => 'decimal:2',
            'service_commission' => 'decimal:2',
            'markup_commission' => 'decimal:2',
            'usd_total' => 'decimal:2',
            'php_total' => 'decimal:2',
            'card_hold_amount' => 'decimal:2',
            'net_commission' => 'decimal:2',
        ];
    }

    public function commissionSlip(): BelongsTo
    {
        return $this->belongsTo(CommissionSlip::class);
    }

    /** A hold only ever applies to a card payment, and the CRM decides that. */
    public function wasHeld(): bool
    {
        return $this->card_hold_amount !== null && (float) $this->card_hold_amount > 0;
    }
}
