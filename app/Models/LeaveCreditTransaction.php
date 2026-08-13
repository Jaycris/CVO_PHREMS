<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LeaveCreditTransaction extends Model
{
    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'transaction_date',
        'amount',
        'reason',
        'leave_request_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * The cash payout for a year-end conversion, if it has been paid.
     *
     * A relation rather than a flag so the "not yet paid" query can be a
     * whereDoesntHave, which cannot drift out of step the way a boolean would.
     */
    public function conversionPayout(): HasOne
    {
        return $this->hasOne(LeaveConversionPayout::class);
    }

    /** Year-end conversions still owed money, oldest first. */
    public function scopeUnpaidConversions(Builder $query): Builder
    {
        return $query->where('reason', 'year_end_cash_conversion')
            ->where('amount', '<', 0)
            ->whereDoesntHave('conversionPayout')
            ->orderBy('transaction_date');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }
}
