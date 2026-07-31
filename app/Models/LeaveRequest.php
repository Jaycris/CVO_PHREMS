<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'start_date',
        'end_date',
        'days_requested',
        'reason',
        'is_lwop',
        'status',
        'manager_id',
        'manager_decision',
        'manager_decided_at',
        'ceo_id',
        'ceo_decision',
        'ceo_decided_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_lwop' => 'boolean',
            'manager_decided_at' => 'datetime',
            'ceo_decided_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function ceo(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'ceo_id');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending_manager' => 'Pending Manager Approval',
            'pending_ceo' => 'Pending CEO/COO Approval',
            'approved' => $this->is_lwop ? 'Approved (Unpaid/LWOP)' : 'Approved',
            'declined' => 'Declined',
        };
    }

    public function statusColor(): string
    {
        return match (true) {
            $this->status === 'approved' && ! $this->is_lwop => 'green',
            $this->status === 'approved' => 'amber',
            $this->status === 'declined' => 'red',
            default => 'blue',
        };
    }
}
