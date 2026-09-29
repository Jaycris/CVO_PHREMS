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
        'half_day_period',
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
            'days_requested' => 'float',
            'is_lwop' => 'boolean',
            'manager_decided_at' => 'datetime',
            'ceo_decided_at' => 'datetime',
        ];
    }

    public const MORNING = 'morning';

    public const AFTERNOON = 'afternoon';

    public function isHalfDay(): bool
    {
        return $this->half_day_period !== null;
    }

    /** "Morning" or "Afternoon", for the approver and the DTR. */
    public function halfDayLabel(): ?string
    {
        return $this->isHalfDay() ? ucfirst($this->half_day_period) : null;
    }

    /**
     * How much time off this is, in words.
     *
     * "0.5 day(s)" reads like a rounding error on an approval screen, so a half
     * day says which half instead.
     */
    public function daysLabel(): string
    {
        if ($this->isHalfDay()) {
            return 'Half day (' . $this->halfDayLabel() . ')';
        }

        $days = (float) $this->days_requested;

        return rtrim(rtrim(number_format($days, 2), '0'), '.') . ' day(s)';
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
