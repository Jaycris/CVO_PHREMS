<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashAdvanceRequest extends Model
{
    protected $fillable = [
        'employee_id',
        'amount_requested',
        'amount_approved',
        'deduction_plan',
        'needed_by',
        'reason',
        'status',
        'amended_by_user_id',
        'amended_at',
        'decided_by_user_id',
        'decided_at',
        'decision_note',
        'cash_advance_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_requested' => 'decimal:2',
            'amount_approved' => 'decimal:2',
            'needed_by' => 'date',
            'amended_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function amendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'amended_by_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function cashAdvance(): BelongsTo
    {
        return $this->belongsTo(CashAdvance::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /** What will actually be released, falling back to what was asked for. */
    public function effectiveAmount(): float
    {
        return (float) ($this->amount_approved ?? $this->amount_requested);
    }

    /** True once an approver has changed the amount away from the request. */
    public function wasAmended(): bool
    {
        return $this->amount_approved !== null
            && (float) $this->amount_approved !== (float) $this->amount_requested;
    }

    /**
     * Per-cutoff repayment, derived rather than stored so it can never disagree
     * with the plan the employee chose.
     */
    public function perCutoffAmount(): float
    {
        return static::perCutoffFor($this->effectiveAmount(), $this->deduction_plan);
    }

    public static function perCutoffFor(float $amount, string $plan): float
    {
        return $plan === 'full_next_payroll'
            ? round($amount, 2)
            : round($amount / 2, 2);
    }

    /** @return array<string, string> */
    public static function deductionPlans(): array
    {
        return [
            'split_two_cutoffs' => 'Split over two cutoffs (15th and 30th)',
            'full_next_payroll' => 'Full amount on the next payroll',
        ];
    }

    public function deductionPlanLabel(): string
    {
        return static::deductionPlans()[$this->deduction_plan] ?? $this->deduction_plan;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'Pending CEO/COO Approval',
            'approved' => 'Approved',
            'declined' => 'Declined',
            'cancelled' => 'Cancelled',
            default => $this->status,
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'approved' => 'green',
            'declined' => 'red',
            'cancelled' => 'neutral',
            default => 'blue',
        };
    }
}
