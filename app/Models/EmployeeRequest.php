<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Anything an employee asks for that somebody has to say yes to.
 *
 * The kind of request is a row in request_types rather than a column here, so
 * a new kind is an entry HR makes rather than a release.
 */
class EmployeeRequest extends Model
{
    protected $fillable = [
        'employee_id', 'request_type_id', 'details', 'status',
        'manager_id', 'decided_at', 'decision_note',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(RequestType::class, 'request_type_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function days(): HasMany
    {
        return $this->hasMany(EmployeeRequestDay::class)->orderBy('work_date');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending_manager');
    }

    /** Requests that still hold a claim on a date — pending or already allowed. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending_manager', 'approved']);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending_manager';
    }

    public function typeName(): string
    {
        return $this->type?->name ?? 'Request';
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending_manager' => 'Waiting for approval',
            'approved' => 'Approved',
            'declined' => 'Declined',
            'cancelled' => 'Withdrawn',
            default => ucfirst($this->status),
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'pending_manager' => 'amber',
            'approved' => 'green',
            'declined' => 'red',
            default => 'neutral',
        };
    }

    public function dayCount(): int
    {
        return $this->days->count();
    }

    /**
     * The dates as a phrase a person can read, or a dash for a request that is
     * not about days at all.
     *
     * A run of consecutive days collapses to a span, because "Aug 4 – Aug 8" is
     * how someone would say it out loud and five separate dates is not.
     */
    public function dateLabel(): string
    {
        $dates = $this->days->pluck('work_date')->sort()->values();

        if ($dates->isEmpty()) {
            return '—';
        }

        if ($dates->count() === 1) {
            return $dates->first()->format('M j, Y');
        }

        $consecutive = $dates->first()->diffInDays($dates->last()) === $dates->count() - 1;

        if ($consecutive) {
            return $dates->first()->format('M j') . ' – ' . $dates->last()->format('M j, Y');
        }

        $shown = $dates->take(3)->map(fn (Carbon $d) => $d->format('M j'))->implode(', ');

        return $dates->count() > 3
            ? $shown . ' +' . ($dates->count() - 3) . ' more'
            : $shown . ' ' . $dates->last()->format('Y');
    }

    /**
     * Whether an employee is approved for a given type on a date.
     *
     * The question attendance and reporting will want: "was she approved to be
     * at home on Tuesday".
     */
    public static function approvedOn(int $employeeId, string $typeCode, Carbon|string $date): bool
    {
        return static::where('employee_id', $employeeId)
            ->where('status', 'approved')
            ->whereHas('type', fn (Builder $t) => $t->where('code', $typeCode))
            ->whereHas('days', fn (Builder $q) => $q->whereDate('work_date', Carbon::parse($date)->toDateString()))
            ->exists();
    }
}
