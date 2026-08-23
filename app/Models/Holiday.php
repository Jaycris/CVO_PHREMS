<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A day the government has declared a holiday.
 *
 * Rows rather than code, because the Philippine list is re-issued by
 * proclamation every year and the movable ones — Eid'l Fitr, Eid'l Adha, the
 * "additional special days" that get added around Christmas — are not knowable
 * in advance. HR types in the proclamation; nobody waits for a release.
 *
 * The three types are not labels. Each answers a different pay question:
 *
 *   Regular Holiday          Paid whether or not it is worked.
 *   Special (Non-Working)    Paid for monthly-paid staff; nobody is expected in.
 *   Special (Working) Day    An ordinary working day. This is the government
 *                            explicitly saying it is *not* a holiday, so not
 *                            turning up is still an absence.
 */
class Holiday extends Model
{
    public const REGULAR = 'regular';

    public const SPECIAL_NON_WORKING = 'special_non_working';

    public const SPECIAL_WORKING = 'special_working';

    /** @var array<string, string> */
    public const TYPES = [
        self::REGULAR => 'Regular Holiday',
        self::SPECIAL_NON_WORKING => 'Special (Non-Working) Day',
        self::SPECIAL_WORKING => 'Special (Working) Day',
    ];

    protected $fillable = ['date', 'name', 'type', 'note'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /**
     * Whether an employee keeps their day's pay without working it.
     *
     * True for the first two types, false for a special working day — which is
     * the whole reason that third category exists.
     */
    public function isPaidWhenNotWorked(): bool
    {
        return $this->type !== self::SPECIAL_WORKING;
    }

    public function scopeBetween(Builder $query, Carbon $start, Carbon $end): Builder
    {
        return $query->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString());
    }

    public function scopeForYear(Builder $query, int $year): Builder
    {
        return $query->whereYear('date', $year);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('date');
    }

    /**
     * The holidays in a range, keyed by date.
     *
     * Only the ones that protect a day's pay are returned. A special working
     * day is an ordinary day as far as payroll is concerned, and including it
     * here would be the one way to accidentally pay for it.
     *
     * When two protected holidays share a date the first wins; they have the
     * same effect on pay, so which one is immaterial.
     *
     * @return array<string, self> date => holiday
     */
    public static function payProtectedBetween(Carbon $start, Carbon $end): array
    {
        return static::query()
            ->between($start, $end)
            ->where('type', '!=', self::SPECIAL_WORKING)
            ->orderBy('date')
            ->get()
            ->keyBy(fn (self $h) => $h->date->toDateString())
            ->all();
    }

    /** The years that actually have holidays on file, newest first. */
    public static function years(): array
    {
        return static::query()
            ->selectRaw('DISTINCT ' . static::yearExpression() . ' as y')
            ->orderByDesc('y')
            ->pluck('y')
            ->map(fn ($y) => (int) $y)
            ->all();
    }

    /** SQLite has no YEAR(); the test suite runs on it and production does not. */
    protected static function yearExpression(): string
    {
        return static::query()->getConnection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%Y', date) AS INTEGER)"
            : 'YEAR(date)';
    }
}
