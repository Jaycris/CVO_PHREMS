<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'accrual_mode',
        'default_annual_credits',
        'monthly_accrual_rate',
        'accrual_day_of_month',
        'resets_annually',
        'allow_carry_over',
        'allow_cash_conversion',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'default_annual_credits' => 'decimal:2',
            'monthly_accrual_rate' => 'decimal:3',
            'resets_annually' => 'boolean',
            'allow_carry_over' => 'boolean',
            'allow_cash_conversion' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(LeaveCreditTransaction::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }
}
