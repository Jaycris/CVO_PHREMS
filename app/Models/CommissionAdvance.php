<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Money handed to an agent before they have earned it, repaid out of their
 * commission runs.
 *
 * The balance is never stored — it is the principal less what the runs have
 * actually taken. A stored balance and a list of repayments eventually
 * disagree, and then nobody can say which one the agent owes.
 */
class CommissionAdvance extends Model
{
    public const ACTIVE = 'active';

    public const ON_HOLD = 'on_hold';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'employee_id', 'reference_no', 'principal_amount', 'amount_per_run',
        'released_on', 'status', 'note', 'approved_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'principal_amount' => 'decimal:2',
            'amount_per_run' => 'decimal:2',
            'released_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CommissionAdvance $advance) {
            $advance->reference_no ??= self::nextReference();
        });
    }

    public static function nextReference(): string
    {
        $today = now()->format('ymd');
        $todayCount = self::whereDate('created_at', now()->toDateString())->count() + 1;

        return 'CV-CA-' . $today . '-' . str_pad((string) $todayCount, 3, '0', STR_PAD_LEFT);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CommissionAdvancePayment::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function repaid(): float
    {
        return round((float) $this->payments->sum('amount'), 2);
    }

    public function balance(): float
    {
        return round(max(0, (float) $this->principal_amount - $this->repaid()), 2);
    }

    public function isSettled(): bool
    {
        return $this->balance() <= 0.004;
    }

    /**
     * What this run should take, given what the slip can bear.
     *
     * The instalment shrinks to fit rather than driving a commission slip
     * negative; the shortfall simply stays on the balance for the next run.
     */
    public function instalmentFor(float $netCeiling): float
    {
        if ($netCeiling <= 0) {
            return 0.0;
        }

        $wanted = $this->amount_per_run === null
            ? $this->balance()
            : min((float) $this->amount_per_run, $this->balance());

        return round(min($wanted, $netCeiling), 2);
    }

    /** Advances that should be collected from a run ending on this date. */
    public function scopeCollectableOn(Builder $query, Carbon|string $periodEnd): Builder
    {
        return $query->where('status', self::ACTIVE)
            ->whereDate('released_on', '<=', Carbon::parse($periodEnd)->toDateString());
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::ON_HOLD => 'On hold',
            self::PAID => 'Fully repaid',
            self::CANCELLED => 'Cancelled',
            default => 'Active',
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::PAID => 'green',
            self::ON_HOLD => 'amber',
            self::CANCELLED => 'neutral',
            default => 'brand',
        };
    }
}
