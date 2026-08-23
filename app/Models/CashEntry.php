<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One movement of money, in or out.
 *
 * There is no bank account on an entry and no opening balance, so this records
 * what moved rather than what is left. That is the question the company
 * actually has — where did the money go last month — and answering the other
 * one honestly would need every account reconciled, which is bookkeeping.
 */
class CashEntry extends Model
{
    public const IN = 'in';

    public const OUT = 'out';

    protected $fillable = [
        'entry_date', 'direction', 'cash_category_id', 'description',
        'amount', 'reference', 'note', 'recorded_by_user_id',
        'source_type', 'source_id',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CashCategory::class, 'cash_category_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function isIn(): bool
    {
        return $this->direction === self::IN;
    }

    /**
     * The amount as it affects the total: positive in, negative out.
     *
     * Amounts are always stored positive and the direction carries the sign.
     * Storing a negative would mean two ways to say the same thing, and sooner
     * or later both would be in the table.
     */
    public function signedAmount(): float
    {
        return $this->isIn() ? (float) $this->amount : -(float) $this->amount;
    }

    /** Whether a person typed this in, as opposed to payroll posting it. */
    public function wasEnteredByHand(): bool
    {
        return $this->source_type === null;
    }

    /*
     * Columns are qualified in these scopes because byCategory joins
     * cash_categories, which has its own `direction`. Unqualified, the join
     * makes the query ambiguous and the whole breakdown falls over.
     */

    public function scopeBetween(Builder $query, Carbon $start, Carbon $end): Builder
    {
        return $query->whereDate('cash_entries.entry_date', '>=', $start->toDateString())
            ->whereDate('cash_entries.entry_date', '<=', $end->toDateString());
    }

    public function scopeInMonth(Builder $query, int $year, int $month): Builder
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();

        return $query->between($start, $start->copy()->endOfMonth());
    }

    public function scopeDirection(Builder $query, string $direction): Builder
    {
        return $query->where('cash_entries.direction', $direction);
    }

    /** Newest first, and stable when several share a date. */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('cash_entries.entry_date')->orderByDesc('cash_entries.id');
    }

    /** The months that actually hold entries, newest first. */
    public static function months(): array
    {
        $expression = static::query()->getConnection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', entry_date)"
            : "DATE_FORMAT(entry_date, '%Y-%m')";

        return static::query()
            ->selectRaw("DISTINCT {$expression} as m")
            ->orderByDesc('m')
            ->pluck('m')
            ->all();
    }
}
