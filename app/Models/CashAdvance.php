<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class CashAdvance extends Model
{
    protected $fillable = [
        'employee_id',
        'reference_no',
        'principal_amount',
        'amount_per_cutoff',
        'start_date',
        'status',
        'approved_by_user_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'principal_amount' => 'decimal:2',
            'amount_per_cutoff' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CashAdvancePayment::class);
    }

    public function totalPaid(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    /**
     * Always derived, never stored — see the migration note. A cached column
     * would go stale the moment a payroll run is recomputed.
     */
    public function remainingBalance(): float
    {
        return round((float) $this->principal_amount - $this->totalPaid(), 2);
    }

    public function isSettled(): bool
    {
        return $this->remainingBalance() <= 0.0;
    }

    public function statusLabel(): string
    {
        return str($this->status)->replace('_', ' ')->title()->toString();
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'paid' => 'green',
            'on_hold' => 'amber',
            'cancelled' => 'red',
            default => 'blue',
        };
    }

    /**
     * What to deduct on one payslip: the agreed instalment, but never more than
     * the outstanding balance, and never more than the payslip can bear.
     */
    public function instalmentFor(float $netCeiling): float
    {
        return round(max(0, min(
            (float) $this->amount_per_cutoff,
            $this->remainingBalance(),
            $netCeiling
        )), 2);
    }

    /** Advances that should be deducted from a period ending on the given date. */
    public function scopeActiveOn(Builder $query, Carbon|string $periodEnd): Builder
    {
        return $query->where('status', 'active')
            ->whereDate('start_date', '<=', Carbon::parse($periodEnd)->toDateString());
    }
}
