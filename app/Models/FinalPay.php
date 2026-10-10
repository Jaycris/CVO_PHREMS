<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The settlement for somebody who has left.
 *
 * Held by default: nothing is paid until clearance is signed off, which is the
 * company's rule and the reason this is a record rather than a calculation
 * somebody does once and forgets.
 */
class FinalPay extends Model
{
    public const HELD = 'held';

    public const CLEARED = 'cleared';

    public const RELEASED = 'released';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'employee_id', 'separation_date', 'for_year',
        'basic_earned', 'thirteenth_month',
        'cash_advance_balance', 'commission_advance_balance',
        'other_deduction', 'other_deduction_label', 'net_amount',
        'status', 'cleared_at', 'cleared_by_user_id', 'released_on', 'emailed_at', 'note',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'separation_date' => 'date',
            'basic_earned' => 'decimal:2',
            'thirteenth_month' => 'decimal:2',
            'cash_advance_balance' => 'decimal:2',
            'commission_advance_balance' => 'decimal:2',
            'other_deduction' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'cleared_at' => 'datetime',
            'released_on' => 'date',
            'emailed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function clearedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleared_by_user_id');
    }

    public function totalDeductions(): float
    {
        return round(
            (float) $this->cash_advance_balance
            + (float) $this->commission_advance_balance
            + (float) $this->other_deduction,
            2,
        );
    }

    public function isPayable(): bool
    {
        return $this->status === self::CLEARED;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::CLEARED => 'Cleared — ready to pay',
            self::RELEASED => 'Paid',
            self::CANCELLED => 'Cancelled',
            default => 'Held for clearance',
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::CLEARED => 'brand',
            self::RELEASED => 'green',
            self::CANCELLED => 'neutral',
            default => 'amber',
        };
    }
}
