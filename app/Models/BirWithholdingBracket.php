<?php

namespace App\Models;

use App\Support\EffectiveDated;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class BirWithholdingBracket extends Model
{
    use EffectiveDated;

    protected $fillable = [
        'period', 'income_from', 'income_to', 'base_tax', 'excess_rate',
        'effective_from', 'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'income_from' => 'decimal:2',
            'income_to' => 'decimal:2',
            'base_tax' => 'decimal:2',
            'excess_rate' => 'decimal:4',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public static function forIncome(float $taxableIncome, Carbon|string $on, string $period = 'semi_monthly'): ?self
    {
        return static::effectiveOn($on)
            ->where('period', $period)
            ->where('income_from', '<=', $taxableIncome)
            ->where(fn (Builder $q) => $q->whereNull('income_to')->orWhere('income_to', '>=', $taxableIncome))
            ->orderByDesc('income_from')
            ->first();
    }

    /** Fixed base for the bracket, plus a rate on whatever exceeds its floor. */
    public function taxFor(float $taxableIncome): float
    {
        $excess = max(0, $taxableIncome - (float) $this->income_from);

        return round((float) $this->base_tax + ($excess * (float) $this->excess_rate), 2);
    }
}
