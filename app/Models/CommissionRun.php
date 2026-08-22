<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class CommissionRun extends Model
{
    protected $fillable = [
        'period_month', 'status',
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
            'period_month' => 'date',
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

    /** Y-m, the form the CRM is asked in. */
    public function month(): string
    {
        return $this->period_month->format('Y-m');
    }

    public function monthLabel(): string
    {
        return $this->period_month->format('F Y');
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

    public function scopeForMonth(Builder $query, Carbon|string $month): Builder
    {
        return $query->whereDate('period_month', Carbon::parse($month)->startOfMonth()->toDateString());
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
