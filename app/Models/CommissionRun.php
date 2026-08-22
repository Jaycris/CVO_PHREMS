<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class CommissionRun extends Model
{
    protected $fillable = [
        'run_type', 'period_start', 'period_end', 'label', 'status',
        'computed_at', 'computed_by_user_id',
        'finalized_at', 'finalized_by_user_id',
        'sent_at', 'sent_by_user_id',
        'agent_count', 'failed_count',
        'total_usd', 'total_php', 'total_card_hold', 'total_net',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'computed_at' => 'datetime',
            'finalized_at' => 'datetime',
            'sent_at' => 'datetime',
            'total_usd' => 'decimal:2',
            'total_php' => 'decimal:2',
            'total_card_hold' => 'decimal:2',
            'total_net' => 'decimal:2',
        ];
    }

    public function slips(): HasMany
    {
        return $this->hasMany(CommissionSlip::class);
    }

    /** Ordered by id: several actions can land in the same second. */
    public function logs(): HasMany
    {
        return $this->hasMany(CommissionRunLog::class)->orderByDesc('id');
    }

    public function computedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'computed_by_user_id');
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by_user_id');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    /** Who this run covers. Chosen by a person — see the pivot's migration. */
    public function agents(): BelongsToMany
    {
        // Qualified: the pivot has an employee_id too, and an unqualified
        // order-by is ambiguous enough for MySQL to refuse the query outright.
        return $this->belongsToMany(Employee::class, 'commission_run_agents')
            ->withTimestamps()
            ->orderBy('employees.employee_id');
    }

    /**
     * The period as a phrase.
     *
     * A whole calendar month says its own name; anything else spells out both
     * ends, because "August 2026" and "Aug 1 – Aug 15" are answers to different
     * questions and an agent paid twice a month needs to see which one they
     * are holding.
     */
    public function periodLabel(): string
    {
        if ($this->label) {
            return $this->label;
        }

        $wholeMonth = $this->period_start->isSameDay($this->period_start->copy()->startOfMonth())
            && $this->period_end->isSameDay($this->period_end->copy()->endOfMonth())
            && $this->period_start->isSameMonth($this->period_end);

        return $wholeMonth
            ? $this->period_start->format('F Y')
            : $this->period_start->format('M j') . ' – ' . $this->period_end->format('M j, Y');
    }

    /**
     * Kept because slips and the PDF still say "the month this covers", and for
     * a fortnight the honest answer is the month it falls in.
     */
    public function monthLabel(): string
    {
        return $this->periodLabel();
    }

    /** Y-m of the period start, for the CRM's month parameter. */
    public function month(): string
    {
        return $this->period_start->format('Y-m');
    }

    public function typeLabel(): string
    {
        return match ($this->run_type) {
            'monthly' => 'Monthly',
            'biweekly' => 'Bi-weekly',
            default => 'Custom',
        };
    }

    public function dayCount(): int
    {
        return (int) $this->period_start->diffInDays($this->period_end) + 1;
    }

    /**
     * Whether the figures can still change.
     *
     * Draft and computed both qualify — recomputing a computed run is how a
     * correction in the CRM is picked up. Finalizing is what stops it.
     */
    public function isMutable(): bool
    {
        return in_array($this->status, ['draft', 'computed'], true);
    }

    public function isFinalized(): bool
    {
        return in_array($this->status, ['finalized', 'sent'], true);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'draft' => 'Not computed',
            'computed' => 'Computed',
            'finalized' => 'Finalized',
            'sent' => 'Sent to agents',
            'cancelled' => 'Cancelled',
            default => ucfirst($this->status),
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'draft' => 'neutral',
            'computed' => 'blue',
            'finalized' => 'amber',
            'sent' => 'green',
            default => 'neutral',
        };
    }

    /** A run covering exactly these dates, whatever its type. */
    public function scopeForPeriod(Builder $query, Carbon|string $start, Carbon|string $end): Builder
    {
        return $query->whereDate('period_start', Carbon::parse($start)->toDateString())
            ->whereDate('period_end', Carbon::parse($end)->toDateString());
    }

    /** Runs whose period overlaps these dates — the double-pay guard. */
    public function scopeOverlapping(Builder $query, Carbon|string $start, Carbon|string $end): Builder
    {
        return $query->whereDate('period_start', '<=', Carbon::parse($end)->toDateString())
            ->whereDate('period_end', '>=', Carbon::parse($start)->toDateString());
    }

    public function log(string $action, ?string $note = null): CommissionRunLog
    {
        $user = Auth::user();

        return $this->logs()->create([
            'action' => $action,
            'note' => $note,
            'user_id' => $user?->id,
            'user_name' => $user?->name,
        ]);
    }
}
