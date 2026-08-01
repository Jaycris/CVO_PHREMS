<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Generates short, human-quotable reference codes such as EMP-2301 / USR-4917.
 *
 * Codes are random rather than sequential so they leak nothing about headcount
 * or hiring order. Uniqueness is enforced twice: this generator retries on
 * collision, and the underlying column carries a unique index, so a race
 * between two concurrent inserts fails loudly instead of duplicating.
 */
trait GeneratesReferenceCode
{
    protected static function generateReferenceCode(string $prefix, string $column, int $digits = 4): string
    {
        $min = (int) str_pad('1', $digits, '0');          // 1000 for 4 digits
        $max = (int) str_repeat('9', $digits);            // 9999 for 4 digits
        $attempts = 0;

        do {
            if (++$attempts > 50) {
                // The keyspace is effectively exhausted; widening $digits is the fix.
                throw new RuntimeException("Unable to allocate a unique {$prefix} code after {$attempts} attempts.");
            }

            $code = $prefix . '-' . random_int($min, $max);
        } while (static::query()->where($column, $code)->exists());

        return $code;
    }

    /** Convenience for building a query constrained to a generated code. */
    protected static function scopeByReferenceCode(Builder $query, string $column, string $code): Builder
    {
        return $query->where($column, $code);
    }
}
