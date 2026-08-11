<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashAdvanceRequest extends Model
{
    protected $fillable = [
        'employee_id',
        'amount_requested',
        'per_cutoff_requested',
        'amount_approved',
        'per_cutoff_approved',
        'needed_by',
        'reason',
        'status',
        'manager_id',
        'manager_decision',
        'manager_decided_at',
        'manager_note',
        'ceo_id',
        'ceo_decision',
        'ceo_decided_at',
        'ceo_note',
        'cash_advance_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_requested' => 'decimal:2',
            'per_cutoff_requested' => 'decimal:2',
            'amount_approved' => 'decimal:2',
            'per_cutoff_approved' => 'decimal:2',
            'needed_by' => 'date',
            'manager_decided_at' => 'datetime',
            'ceo_decided_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function ceo(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'ceo_id');
    }

    public function cashAdvance(): BelongsTo
    {
        return $this->belongsTo(CashAdvance::class);
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['pending_manager', 'pending_ceo'], true);
    }

    /** The figures that will be released, falling back to what was asked for. */
    public function effectiveAmount(): float
    {
        return (float) ($this->amount_approved ?? $this->amount_requested);
    }

    public function effectivePerCutoff(): float
    {
        return (float) ($this->per_cutoff_approved ?? $this->per_cutoff_requested);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending_manager' => 'Pending Manager Approval',
            'pending_ceo' => 'Pending CEO/COO Approval',
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
