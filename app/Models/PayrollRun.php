<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class PayrollRun extends Model
{
    protected $fillable = [
        'run_type', 'cutoff', 'period_start', 'period_end', 'pay_date', 'status',
        'computed_at', 'computed_by_user_id',
        'finalized_at', 'finalized_by_user_id',
        'paid_at', 'paid_by_user_id',
        'employee_count', 'total_gross', 'total_deductions', 'total_net', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'pay_date' => 'date',
            'computed_at' => 'datetime',
            'finalized_at' => 'datetime',
            'paid_at' => 'datetime',
            'total_gross' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'total_net' => 'decimal:2',
        ];
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    /**
     * Ordered by id rather than timestamp — several actions can land in the
     * same second, and then created_at alone cannot say which came first.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(PayrollRunLog::class)->orderByDesc('id');
    }

    public function computedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'computed_by_user_id');
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by_user_id');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    /**
     * Runs whose period covers a date and whose figures are already locked.
     *
     * Only finalized and paid runs count. A day sitting inside a draft or a
     * run still being computed is not settled — telling an employee otherwise
     * and then changing the number is worse than saying nothing.
     */
    public function scopeSettledOver(Builder $query, Carbon|string $from, Carbon|string $to): Builder
    {
        return $query->whereIn('status', ['finalized', 'paid'])
            ->whereDate('period_start', '<=', Carbon::parse($to)->toDateString())
            ->whereDate('period_end', '>=', Carbon::parse($from)->toDateString());
    }

    public function coversDate(Carbon|string $date): bool
    {
        $on = Carbon::parse($date)->startOfDay();

        return $on->betweenIncluded($this->period_start->startOfDay(), $this->period_end->startOfDay());
    }

    /**
     * Whether the figures may still change. Everything that writes to a run or
     * its payslips checks this first.
     */
    public function isMutable(): bool
    {
        return in_array($this->status, ['draft', 'computed'], true);
    }

    public function isLocked(): bool
    {
        return ! $this->isMutable();
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'draft' => 'Draft',
            'computed' => 'Computed',
            'finalized' => 'Finalized',
            'paid' => 'Paid',
            'cancelled' => 'Cancelled',
            default => $this->status,
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'paid' => 'green',
            'finalized' => 'brand',
            'computed' => 'amber',
            'cancelled' => 'red',
            default => 'neutral',
        };
    }

    public function periodLabel(): string
    {
        return $this->period_start->format('M j') . ' – ' . $this->period_end->format('M j, Y');
    }

    public function log(string $action, ?string $note = null): PayrollRunLog
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
