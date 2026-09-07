<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A notice the company puts in front of everybody.
 *
 * Dated rather than simply posted: most notices are about a day, and giving
 * them a window means the board clears itself instead of waiting for somebody
 * to remember. A notice with no end date stays until it is taken down, which is
 * what a standing one — a changed payroll date, a new office rule — needs.
 */
class Announcement extends Model
{
    use HasFactory;

    public const NEWS = 'news';

    public const EVENT = 'event';

    public const NOTICE = 'notice';

    public const URGENT = 'urgent';

    /**
     * What sort of notice it is.
     *
     * Only colour and ordering hang off this. It is deliberately not a
     * permission or a delivery rule — marking something urgent must not be the
     * thing that decides who is emailed, or every notice becomes urgent.
     *
     * @var array<string, string>
     */
    public const KINDS = [
        self::NEWS => 'News',
        self::EVENT => 'Event',
        self::NOTICE => 'Reminder',
        self::URGENT => 'Important',
    ];

    protected $fillable = [
        'title',
        'body',
        'kind',
        'starts_on',
        'ends_on',
        'is_pinned',
        'published_at',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_pinned' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isDraft(): bool
    {
        return $this->published_at === null;
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? self::KINDS[self::NEWS];
    }

    public function kindColor(): string
    {
        return match ($this->kind) {
            self::URGENT => 'red',
            self::EVENT => 'brand',
            self::NOTICE => 'amber',
            default => 'blue',
        };
    }

    public function icon(): string
    {
        return match ($this->kind) {
            self::URGENT => 'shield-check',
            self::EVENT => 'calendar',
            self::NOTICE => 'clipboard',
            default => 'bell',
        };
    }

    /**
     * Whether it is on the board on a given day.
     *
     * A draft never is, however its dates read. That is the whole point of
     * keeping published_at separate from starts_on.
     */
    public function isLiveOn(Carbon|string $date): bool
    {
        if ($this->isDraft()) {
            return false;
        }

        $on = Carbon::parse($date)->startOfDay();

        return $on->gte($this->starts_on->startOfDay())
            && ($this->ends_on === null || $on->lte($this->ends_on->startOfDay()));
    }

    /**
     * Published, and covering the given day.
     *
     * whereDate rather than a plain comparison because the test suite runs on
     * SQLite and production on MySQL. MySQL will happily match a date column
     * against a 'Y-m-d' string; SQLite compares it as text and quietly misses.
     */
    public function scopeLiveOn(Builder $query, Carbon|string $date): Builder
    {
        $on = Carbon::parse($date)->toDateString();

        return $query
            ->whereNotNull('published_at')
            ->whereDate('starts_on', '<=', $on)
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $on));
    }

    /** Pinned first, then whichever started most recently. */
    public function scopeForBoard(Builder $query): Builder
    {
        return $query->orderByDesc('is_pinned')->orderByDesc('starts_on')->orderByDesc('id');
    }

    public function rangeLabel(): string
    {
        if ($this->ends_on === null) {
            return 'From ' . $this->starts_on->format('M d, Y');
        }

        if ($this->starts_on->isSameDay($this->ends_on)) {
            return $this->starts_on->format('M d, Y');
        }

        if ($this->starts_on->isSameMonth($this->ends_on)) {
            return $this->starts_on->format('M d') . ' – ' . $this->ends_on->format('d, Y');
        }

        return $this->starts_on->format('M d, Y') . ' – ' . $this->ends_on->format('M d, Y');
    }

    /**
     * Where it sits relative to today, for a reader deciding whether to care.
     *
     * "Today" and "Ends today" earn their place on the board; the rest are for
     * the management list, where a notice may be listed long before or after
     * anybody sees it.
     */
    public function timing(?Carbon $today = null): string
    {
        $today = ($today ?? Carbon::today())->startOfDay();

        if ($this->starts_on->startOfDay()->gt($today)) {
            return 'Starts ' . $this->starts_on->format('M d');
        }

        if ($this->ends_on !== null && $this->ends_on->startOfDay()->lt($today)) {
            return 'Ended ' . $this->ends_on->format('M d');
        }

        if ($this->ends_on !== null && $this->ends_on->isSameDay($today)) {
            return 'Ends today';
        }

        if ($this->starts_on->isSameDay($today)) {
            return 'Posted today';
        }

        return 'Running now';
    }
}
