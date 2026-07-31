<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $fillable = ['name', 'description'];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $term === ''
            ? $query
            : $query->where(fn (Builder $q) => $q->where('name', 'like', "%{$term}%")->orWhere('description', 'like', "%{$term}%"));
    }
}
