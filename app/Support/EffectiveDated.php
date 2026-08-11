<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Rate tables that change by government circular.
 *
 * Every lookup is made as of a date rather than "the current row", so a payroll
 * run recomputed next year reproduces the same figures it produced when it was
 * finalised. It also lets next year's rates be loaded the moment they are
 * published, without them taking effect early.
 */
trait EffectiveDated
{
    public function scopeEffectiveOn(Builder $query, Carbon|string $date): Builder
    {
        $on = Carbon::parse($date)->toDateString();

        return $query
            ->whereDate('effective_from', '<=', $on)
            ->where(fn (Builder $q) => $q
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $on));
    }
}
