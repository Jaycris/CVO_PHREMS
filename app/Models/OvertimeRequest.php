<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class OvertimeRequest extends Model
{
    protected $fillable = [
        'employee_id',
        'work_date',
        'hours_requested',
        'hours_approved',
        'reason',
        'status',
        'manager_id',
        'manager_decision',
        'manager_decided_at',
        'manager_note',
        'consumed_payroll_run_id',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'hours_requested' => 'decimal:2',
            'hours_approved' => 'decimal:2',
            'manager_decided_at' => 'datetime',
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

    /**
     * Hours that actually reach payroll. Only an approved request pays, and it
     * pays what the manager approved rather than what was requested.
     */
    public function effectiveHours(): float
    {
        return $this->status === 'approved'
            ? (float) ($this->hours_approved ?? 0)
            : 0.0;
    }

    public function isPending(): bool
    {
        return $this->status === 'pending_manager';
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending_manager' => 'Pending Manager Approval',
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

    /** Approved overtime within a payroll period. */
    public function scopeApprovedBetween(Builder $query, Carbon|string $start, Carbon|string $end): Builder
    {
        return $query->where('status', 'approved')
            ->whereBetween('work_date', [
                Carbon::parse($start)->toDateString(),
                Carbon::parse($end)->toDateString(),
            ]);
    }
}
