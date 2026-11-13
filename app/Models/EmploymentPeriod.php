<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One stretch of employment.
 *
 * An employee holds one hire date and one last day, which cannot describe
 * somebody who left and came back — rehiring overwrites both and the earlier
 * period disappears. These rows are the history those two fields cannot hold.
 */
class EmploymentPeriod extends Model
{
    protected $fillable = [
        'employee_id',
        'started_on',
        'ended_on',
        'end_reason',
        'note',
        'recorded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'started_on' => 'date',
            'ended_on' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /** The one still running, if any. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_on');
    }

    public function isOpen(): bool
    {
        return $this->ended_on === null;
    }

    public function covers(Carbon|string $date): bool
    {
        $on = Carbon::parse($date)->startOfDay();

        return $this->started_on->lte($on)
            && ($this->ended_on === null || $this->ended_on->gte($on));
    }

    /** "Sep 1, 2026 – Oct 30, 2026", or "– present" while it is open. */
    public function label(): string
    {
        return $this->started_on->format('M j, Y') . ' – '
            . ($this->ended_on?->format('M j, Y') ?? 'present');
    }

    /**
     * Whole months served, rounded down.
     *
     * Months rather than years because the spans here are short — somebody
     * with two periods of five months reads better as "10 months" than as
     * "0 years" twice.
     */
    public function months(): int
    {
        return (int) $this->started_on->diffInMonths($this->ended_on ?? Carbon::today());
    }

    public function endReasonLabel(): ?string
    {
        if ($this->end_reason === null) {
            return null;
        }

        return Employee::SEPARATION_TYPES[$this->end_reason] ?? ucfirst($this->end_reason);
    }
}
