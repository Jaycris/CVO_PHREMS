<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One payment to a sales agent, made outside the payroll run.
 */
class AgentPayment extends Model
{
    protected $fillable = [
        'employee_id',
        'month',
        'description',
        'amount',
        'mtd_usd',
        'paid_on',
        'reference',
        'note',
        'cash_entry_id',
        'recorded_by_user_id',
        'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'mtd_usd' => 'decimal:2',
            'paid_on' => 'date',
            'notified_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function cashEntry(): BelongsTo
    {
        return $this->belongsTo(CashEntry::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /**
     * "September 2026".
     *
     * The "!" resets the day to the 1st. Without it, parsing "2026-02" on the
     * 31st of a month overflows into March.
     */
    public function monthLabel(): string
    {
        return Carbon::createFromFormat('!Y-m', $this->month)->format('F Y');
    }

    /** Printed on the slip, so a question about it has something to quote. */
    public function referenceCode(): string
    {
        return 'CV-AP-' . $this->paid_on->format('ymd') . '-' . str_pad((string) $this->id, 3, '0', STR_PAD_LEFT);
    }
}
