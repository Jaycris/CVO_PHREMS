<?php

namespace App\Models;

use App\Support\EffectiveDated;
use Illuminate\Database\Eloquent\Model;

class PhilhealthRate extends Model
{
    use EffectiveDated;

    protected $fillable = [
        'premium_rate', 'salary_floor', 'salary_ceiling', 'employee_share_ratio',
        'effective_from', 'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'premium_rate' => 'decimal:4',
            'salary_floor' => 'decimal:2',
            'salary_ceiling' => 'decimal:2',
            'employee_share_ratio' => 'decimal:4',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /** Monthly basic pay, held within the floor and ceiling the premium applies to. */
    public function premiumBase(float $monthlySalary): float
    {
        return min(max($monthlySalary, (float) $this->salary_floor), (float) $this->salary_ceiling);
    }
}
