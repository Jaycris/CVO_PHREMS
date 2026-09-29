<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class LeaveRequest extends Model
{
    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'start_date',
        'end_date',
        'days_requested',
        'half_day_period',
        'half_day_start',
        'half_day_end',
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

    /** Which half of their own shift, not a time of day — see WorkSchedule::halfShiftWindows(). */
    public const FIRST_HALF = 'first';

    public const SECOND_HALF = 'second';

    public function isHalfDay(): bool
    {
        return $this->half_day_period !== null;
    }

    /**
     * The hours they are away for, as the clock shows them.
     *
     * "10:00 PM - 2:00 AM" rather than "morning", because a graveyard shift has
     * no morning and the approver needs to know when to expect them.
     */
    public function halfDayLabel(): ?string
    {
        if (! $this->isHalfDay()) {
            return null;
        }

        if ($this->half_day_start === null || $this->half_day_end === null) {
            // No schedule was on file when they filed it.
            return $this->half_day_period === self::SECOND_HALF ? 'second half of shift' : 'first half of shift';
        }

        // Parsed rather than read to a format: the column hands back "22:00:00"
        // on MySQL and "22:00" as it was written on SQLite.
        $clock = fn (string $time) => Carbon::parse($time)->format('g:i A');

        return $clock($this->half_day_start) . ' - ' . $clock($this->half_day_end);
    }

    /**
     * How much time off this is, in words.
     *
     * "0.5 day(s)" reads like a rounding error on an approval screen, so a half
     * day says which hours instead.
     */
    public function daysLabel(): string
    {
        if ($this->isHalfDay()) {
            return 'Half day Leave (' . $this->halfDayLabel() . ')';
        }

        $days = (float) $this->days_requested;

        return rtrim(rtrim(number_format($days, 2), '0'), '.') . ' day(s)';
    }

    /**
     * The dates in words: "from … to …" for a range, "on …" for one day.
     *
     * A half day is always one date, and "from Aug 12 to Aug 12" reads like a
     * mistake in an email somebody's manager is skim-reading.
     */
    public function datesLabel(): string
    {
        if ($this->start_date->isSameDay($this->end_date)) {
            return 'on ' . $this->start_date->format('M d, Y');
        }

        return 'from ' . $this->start_date->format('M d, Y') . ' to ' . $this->end_date->format('M d, Y');
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
