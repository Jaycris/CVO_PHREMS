<?php

namespace App\Models;

use App\Support\EffectiveDated;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class PagibigRate extends Model
{
    use EffectiveDated;

    protected $fillable = [
        'salary_from', 'salary_to', 'employee_rate', 'employer_rate',
        'max_contribution_base', 'effective_from', 'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'salary_from' => 'decimal:2',
            'salary_to' => 'decimal:2',
            'employee_rate' => 'decimal:4',
            'employer_rate' => 'decimal:4',
            'max_contribution_base' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public static function forSalary(float $monthlySalary, Carbon|string $on): ?self
    {
        $rows = static::effectiveOn($on)->orderBy('salary_from');

        return (clone $rows)
            ->where('salary_from', '<=', $monthlySalary)
            ->where(fn (Builder $q) => $q->whereNull('salary_to')->orWhere('salary_to', '>=', $monthlySalary))
            ->first()
            ?? (clone $rows)->first();
    }

    /**
     * The rate is applied to a capped base rather than to actual pay, which is
     * what holds both sides at the statutory maximum.
     */
    public function contributionBase(float $monthlySalary): float
    {
        return min($monthlySalary, (float) $this->max_contribution_base);
    }
}
