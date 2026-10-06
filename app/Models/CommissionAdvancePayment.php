<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One run's repayment of a commission advance. */
class CommissionAdvancePayment extends Model
{
    protected $fillable = [
        'commission_advance_id', 'commission_run_id', 'commission_slip_id', 'amount', 'paid_on',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_on' => 'date',
        ];
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(CommissionAdvance::class, 'commission_advance_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(CommissionRun::class, 'commission_run_id');
    }

    public function slip(): BelongsTo
    {
        return $this->belongsTo(CommissionSlip::class, 'commission_slip_id');
    }

    public function scopeForRun(Builder $query, int $commissionRunId): Builder
    {
        return $query->where('commission_run_id', $commissionRunId);
    }
}
