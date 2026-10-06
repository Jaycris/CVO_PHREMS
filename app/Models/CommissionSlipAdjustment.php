<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Something added to or taken off a commission slip by a person, not the CRM. */
class CommissionSlipAdjustment extends Model
{
    protected $fillable = [
        'commission_slip_id', 'type', 'label', 'amount', 'note',
        'created_by_user_id', 'created_by_name',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function slip(): BelongsTo
    {
        return $this->belongsTo(CommissionSlip::class, 'commission_slip_id');
    }

    public function isEarning(): bool
    {
        return $this->type === 'earning';
    }
}
