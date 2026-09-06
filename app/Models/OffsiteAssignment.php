<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Days worked for the company away from the punch clock.
 *
 * Nobody is going to remember to clock in from a trade stand, and without a
 * record of that the day reads as an absence and takes a day's pay.
 */
class OffsiteAssignment extends Model
{
    use HasFactory;

    /** Worked away from the office — an exhibit, a client visit, a booth. */
    public const WORKED = 'worked';

    /** A day given off to make up for working a weekend or a holiday. */
    public const DAY_OFF = 'day_off';

    /**
     * Payroll treats both identically: a paid day nobody clocks in for.
     *
     * They are kept apart because the record has to say what actually
     * happened. Calling a rest day given after a weekend exhibit "worked
     * off-site" puts a false statement on a payslip to save a column.
     *
     * @var array<string, string>
     */
    public const KINDS = [
        self::WORKED => 'Worked off-site',
        self::DAY_OFF => 'Day off in lieu',
    ];

    protected $fillable = [
        'employee_id',
        'start_date',
        'end_date',
        'kind',
        'reason',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Assignments overlapping a period, however partially. */
    public function scopeOverlapping(Builder $query, Carbon|string $start, Carbon|string $end): Builder
    {
        return $query
            ->whereDate('start_date', '<=', Carbon::parse($end)->toDateString())
            ->whereDate('end_date', '>=', Carbon::parse($start)->toDateString());
    }

    public function covers(Carbon|string $date): bool
    {
        $on = Carbon::parse($date)->startOfDay();

        return $on->betweenIncluded($this->start_date->startOfDay(), $this->end_date->startOfDay());
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? self::KINDS[self::WORKED];
    }

    public function isDayOff(): bool
    {
        return $this->kind === self::DAY_OFF;
    }

    /** Inclusive of both ends: 8th to 13th is six days, not five. */
    public function dayCount(): int
    {
        return $this->start_date->diffInDays($this->end_date) + 1;
    }

    public function rangeLabel(): string
    {
        if ($this->start_date->isSameDay($this->end_date)) {
            return $this->start_date->format('M d, Y');
        }

        // "Sep 8 – 13, 2026" when it stays inside one month, which most do.
        if ($this->start_date->isSameMonth($this->end_date)) {
            return $this->start_date->format('M d') . ' – ' . $this->end_date->format('d, Y');
        }

        return $this->start_date->format('M d, Y') . ' – ' . $this->end_date->format('M d, Y');
    }
}
