<?php

namespace App\Models;

use App\Support\EffectiveDated;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class SssBracket extends Model
{
    use EffectiveDated;

    protected $fillable = [
        'salary_from', 'salary_to', 'monthly_salary_credit',
        'employee_share', 'employer_share', 'employee_compensation',
        'employee_mpf_share', 'employer_mpf_share',
        'effective_from', 'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'salary_from' => 'decimal:2',
            'salary_to' => 'decimal:2',
            'monthly_salary_credit' => 'decimal:2',
            'employee_share' => 'decimal:2',
            'employer_share' => 'decimal:2',
            'employee_compensation' => 'decimal:2',
            'employee_mpf_share' => 'decimal:2',
            'employer_mpf_share' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /**
     * The bracket a monthly salary falls in.
     *
     * Pay below the lowest bracket clamps up to it and pay above the highest
     * clamps down, rather than returning nothing — a salary outside the table
     * still owes a contribution, and returning null here would silently
     * under-deduct.
     */
    public static function forSalary(float $monthlySalary, Carbon|string $on): ?self
    {
        $rows = static::effectiveOn($on)->orderBy('salary_from');

        $match = (clone $rows)
            ->where('salary_from', '<=', $monthlySalary)
            ->where(fn (Builder $q) => $q->whereNull('salary_to')->orWhere('salary_to', '>=', $monthlySalary))
            ->first();

        return $match
            ?? (clone $rows)->orderBy('salary_from')->first();
    }
}
