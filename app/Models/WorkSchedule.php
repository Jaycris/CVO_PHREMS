<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class WorkSchedule extends Model
{
    /** Fallback when work_days has never been set (MySQL forbids json defaults). */
    public const DEFAULT_WORK_DAYS = [1, 2, 3, 4, 5];

    public const NIGHT_WINDOW_START = '22:00';

    public const NIGHT_WINDOW_END = '06:00';

    protected $fillable = [
        'name',
        'start_time',
        'end_time',
        'lunch_break_minutes',
        'coffee_break_minutes',
        'work_days',
        'night_differential_eligible',
    ];

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime:H:i',
            'end_time' => 'datetime:H:i',
            'work_days' => 'array',
            'night_differential_eligible' => 'boolean',
        ];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeScheduleAssignment::class);
    }

    /**
     * ISO-8601 day numbers this schedule is worked on (1 = Mon .. 7 = Sun).
     *
     * @return list<int>
     */
    public function workDays(): array
    {
        $days = $this->work_days;

        return empty($days) ? self::DEFAULT_WORK_DAYS : array_values(array_map('intval', $days));
    }

    public function isWorkDay(Carbon|string $date): bool
    {
        return in_array(Carbon::parse($date)->dayOfWeekIso, $this->workDays(), true);
    }

    public function isRestDay(Carbon|string $date): bool
    {
        return ! $this->isWorkDay($date);
    }

    /** A graveyard shift ends on the following calendar day. */
    public function crossesMidnight(): bool
    {
        return $this->end_time->format('H:i') <= $this->start_time->format('H:i');
    }

    /**
     * Whether any part of the shift falls inside the 22:00-06:00 night window.
     * Both the shift and the window can wrap past midnight, so this compares
     * them on a normalised minutes-from-midnight timeline.
     */
    public function overlapsNightWindow(string $windowStart = self::NIGHT_WINDOW_START, string $windowEnd = self::NIGHT_WINDOW_END): bool
    {
        $toMinutes = fn (string $time): int => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);

        $shift = $this->spanToRanges($toMinutes($this->start_time->format('H:i')), $toMinutes($this->end_time->format('H:i')));
        $window = $this->spanToRanges($toMinutes($windowStart), $toMinutes($windowEnd));

        foreach ($shift as [$shiftStart, $shiftEnd]) {
            foreach ($window as [$winStart, $winEnd]) {
                if ($shiftStart < $winEnd && $winStart < $shiftEnd) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Night differential is time-window based, not shift-name based. An explicit
     * flag overrides the derived value so HR can force it either way.
     */
    public function qualifiesForNightDifferential(): bool
    {
        return $this->night_differential_eligible ?? $this->overlapsNightWindow();
    }

    /** Scheduled workdays between two dates, inclusive of both endpoints. */
    public function expectedWorkdaysBetween(Carbon|string $from, Carbon|string $to): int
    {
        $cursor = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        $count = 0;

        while ($cursor->lte($end)) {
            if ($this->isWorkDay($cursor)) {
                $count++;
            }
            $cursor->addDay();
        }

        return $count;
    }

    /**
     * Splits a possibly-midnight-crossing span into 1-2 non-wrapping ranges.
     *
     * @return list<array{int, int}>
     */
    protected function spanToRanges(int $start, int $end): array
    {
        return $start < $end
            ? [[$start, $end]]
            : [[$start, 1440], [0, $end]];
    }
}
