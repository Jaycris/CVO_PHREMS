<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashAdvancePayment extends Model
{
    protected $fillable = [
        'cash_advance_id',
        'payroll_run_id',
        'payslip_id',
        'amount',
        'paid_on',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_on' => 'date',
        ];
    }

    public function cashAdvance(): BelongsTo
    {
        return $this->belongsTo(CashAdvance::class);
    }

    /**
     * Payments taken by one payroll run. Deleting these is how a recompute
     * releases the debt — see CashAdvanceService::reverseForRun().
     */
    public function scopeForRun(Builder $query, int $payrollRunId): Builder
    {
        return $query->where('payroll_run_id', $payrollRunId);
    }
}
