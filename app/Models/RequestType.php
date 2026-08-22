<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A kind of request an employee can file.
 *
 * Rows rather than code, so HR adds "Overtime pre-approval" or "Uniform" the
 * day they need it instead of asking for a release.
 */
class RequestType extends Model
{
    protected $fillable = [
        'code', 'name', 'description', 'instructions',
        'needs_dates', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'needs_dates' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // The code is what other parts of the app look a type up by, so it is
        // derived once from the name and then left alone — renaming "COE" to
        // "Certificate of Employment" must not orphan anything pointing at it.
        static::creating(function (self $type): void {
            $type->code ??= Str::slug($type->name, '_');
        });
    }

    public function requests(): HasMany
    {
        return $this->hasMany(EmployeeRequest::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
