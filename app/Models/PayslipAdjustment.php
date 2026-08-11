<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayslipAdjustment extends Model
{
    protected $fillable = ['payslip_id', 'type', 'label', 'amount', 'note', 'created_by_user_id', 'created_by_name'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    public function isEarning(): bool
    {
        return $this->type === 'earning';
    }
}
