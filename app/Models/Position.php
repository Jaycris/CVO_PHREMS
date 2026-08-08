<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Position extends Model
{
    protected $fillable = ['title', 'description', 'is_supervisory'];

    protected function casts(): array
    {
        return [
            'is_supervisory' => 'boolean',
        ];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /** Positions people can be assigned to report to. */
    public function scopeSupervisory(Builder $query): Builder
    {
        return $query->where('is_supervisory', true);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $term === ''
            ? $query
            : $query->where(fn (Builder $q) => $q->where('title', 'like', "%{$term}%")->orWhere('description', 'like', "%{$term}%"));
    }
}
