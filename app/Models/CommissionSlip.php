<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An agent's commission slip for one month, frozen when the run was computed.
 *
 * Nothing here is calculated by this app. The CRM works out every figure; the
 * run copies them down and they stop moving. That is the difference between a
 * slip and a screen — an agent can be paid against this.
 */
class CommissionSlip extends Model
{
    protected $fillable = [
        'commission_run_id', 'employee_id', 'employee_snapshot',
        'agent_name', 'team', 'work_type',
        'commission_scheme', 'scheme_rules',
        'mtd', 'target', 'mtd_percent',
        'service_commission', 'markup_commission', 'usd_total', 'exchange_rate', 'php_total',
        'card_hold_percent', 'card_hold_amount', 'net_commission',
        'statement_supplied', 'transaction_count', 'fetch_error', 'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'employee_snapshot' => 'array',
            'scheme_rules' => 'array',
            'mtd' => 'decimal:2',
            'target' => 'decimal:2',
            'mtd_percent' => 'decimal:2',
            'service_commission' => 'decimal:2',
            'markup_commission' => 'decimal:2',
            'usd_total' => 'decimal:2',
            'exchange_rate' => 'decimal:4',
            'php_total' => 'decimal:2',
            'card_hold_percent' => 'decimal:2',
            'card_hold_amount' => 'decimal:2',
            'net_commission' => 'decimal:2',
            'statement_supplied' => 'boolean',
            'notified_at' => 'datetime',
        ];
    }

    public function commissionRun(): BelongsTo
    {
        return $this->belongsTo(CommissionRun::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CommissionSlipLine::class)->orderBy('sort_order');
    }

    /** Only the ones an agent may actually see. */
    public function scopeReleased(Builder $query): Builder
    {
        return $query->whereNotNull('notified_at');
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->whereNotNull('fetch_error');
    }

    public function isReleased(): bool
    {
        return $this->notified_at !== null;
    }

    public function failed(): bool
    {
        return $this->fetch_error !== null;
    }

    /**
     * The name on the slip.
     *
     * The snapshot first, so a slip issued in August still prints the name it
     * was issued under after a marriage or a correction in September.
     */
    public function employeeName(): string
    {
        return $this->employee_snapshot['name']
            ?? ($this->agent_name ?: ($this->employee?->fullName() ?: 'Unknown'));
    }

    public function employeeCode(): string
    {
        return $this->employee_snapshot['employee_id'] ?? ($this->employee?->employee_id ?? '');
    }

    public function monthLabel(): string
    {
        return $this->commissionRun?->monthLabel() ?? '';
    }

    /** Team and work type as one phrase, skipping whichever is missing. */
    public function teamLabel(): string
    {
        return trim(implode(' · ', array_filter([$this->team, $this->work_type]))) ?: '—';
    }
}
