<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Permission\Traits\HasPermissions;

/**
 * A position is the job, and it carries the default access for everyone doing
 * that job. Setting up a new HR hire is then a matter of assigning the position
 * rather than ticking the same boxes again — and revising what HR may see
 * updates every HR user at once, because the link is live rather than copied.
 */
class Position extends Model
{
    use HasFactory;

    use HasPermissions;

    /**
     * Permissions are resolved per guard, and a position is not an auth
     * provider model, so it cannot infer one. Everything in this app signs in
     * through the web guard.
     */
    public string $guard_name = 'web';

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
