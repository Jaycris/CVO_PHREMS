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
 * The types are not labels. Each answers a different pay question:
 *
 *   Regular Holiday          Paid whether or not it is worked.
 *   Special (Non-Working)    Paid for monthly-paid staff; nobody is expected in.
 *   Special (Working) Day    An ordinary working day. This is the government
 *                            explicitly saying it is *not* a holiday, so not
 *                            turning up is still an absence.
 *   Paid Day Off             A day the company simply gives, with no Philippine
 *                            statute behind it. This is where US holidays and
 *                            company shutdowns live — the first three are
 *                            Philippine Labor Code categories and calling
 *                            Thanksgiving a "Special (Non-Working) Day" would
 *                            be inventing a legal status it does not have.
 *
 * Observance is a separate question from pay. It records whose holiday it is,
 * because this company follows both Philippine and American calendars and a
 * list that cannot tell them apart is a list nobody trusts.
 */
class Holiday extends Model
{
    public const REGULAR = 'regular';

    public const SPECIAL_NON_WORKING = 'special_non_working';

    public const SPECIAL_WORKING = 'special_working';

    public const PAID_DAY_OFF = 'paid_day_off';

    /** @var array<string, string> */
    public const TYPES = [
        self::REGULAR => 'Regular Holiday',
        self::SPECIAL_NON_WORKING => 'Special (Non-Working) Day',
        self::SPECIAL_WORKING => 'Special (Working) Day',
        self::PAID_DAY_OFF => 'Paid Day Off',
    ];

    public const PHILIPPINES = 'philippines';

    public const UNITED_STATES = 'united_states';

    public const COMPANY = 'company';

    /** @var array<string, string> */
    public const OBSERVANCES = [
        self::PHILIPPINES => 'Philippines',
        self::UNITED_STATES => 'United States',
        self::COMPANY => 'Company',
    ];

    /**
     * The types that make sense for each observance.
     *
     * Only Philippine holidays carry Labor Code categories. Offering "Regular
     * Holiday" for the Fourth of July would invite somebody to tick it and
     * later expect the 200% premium that goes with it.
     *
     * @var array<string, list<string>>
     */
    public const TYPES_BY_OBSERVANCE = [
        self::PHILIPPINES => [self::REGULAR, self::SPECIAL_NON_WORKING, self::SPECIAL_WORKING],
        self::UNITED_STATES => [self::PAID_DAY_OFF, self::SPECIAL_WORKING],
        self::COMPANY => [self::PAID_DAY_OFF, self::SPECIAL_WORKING],
    ];

    protected $fillable = ['date', 'name', 'type', 'observance', 'note', 'worked_premium_percent'];

    /**
     * What working this holiday is worth, on top of the day's own pay.
     *
     * @var array<int, string>
     */
    public const WORKED_PREMIUMS = [
        0 => 'No extra pay',
        30 => 'Extra 30% (special non-working)',
        100 => 'Double pay (regular holiday)',
    ];

    /**
     * The premium usually wanted for a type, offered when adding a holiday.
     *
     * Only a starting point. The company follows some Philippine holidays and
     * some American ones, and enters days under whichever calendar it treats
     * them by, so the type is a hint about the premium and never the answer.
     */
    public static function defaultPremiumFor(string $type): int
    {
        return match ($type) {
            self::REGULAR => 100,
            self::SPECIAL_NON_WORKING => 30,
            default => 0,
        };
    }

    /** The premium as a multiplier of one day's pay. */
    public function premiumFraction(): float
    {
        return (int) $this->worked_premium_percent / 100;
    }

    public function premiumLabel(): string
    {
        $percent = (int) $this->worked_premium_percent;

        return self::WORKED_PREMIUMS[$percent] ?? ($percent . '% extra');
    }

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function observanceLabel(): string
    {
        return self::OBSERVANCES[$this->observance] ?? $this->observance;
    }

    /**
     * The type options that apply to an observance, as value => label.
     *
     * Ordered as listed in TYPES_BY_OBSERVANCE rather than as listed in TYPES,
     * because the first one is what a new holiday defaults to — and for a US
     * or company holiday that has to be the paid day off, not the working day.
     */
    public static function typesFor(string $observance): array
    {
        $allowed = self::TYPES_BY_OBSERVANCE[$observance] ?? array_keys(self::TYPES);

        $options = [];

        foreach ($allowed as $type) {
            $options[$type] = self::TYPES[$type];
        }

        return $options;
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

    public function scopeObservance(Builder $query, string $observance): Builder
    {
        return $query->where('observance', $observance);
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
