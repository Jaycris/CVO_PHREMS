<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The commission plans an agent can be on.
 *
 * Rows rather than code, because this list is not the HRIS's to invent. The CRM
 * works out every commission figure; the HRIS only records which plan a person
 * is on and prints what the CRM sends back. If the two lists disagree, the
 * HRIS is describing someone's pay by a name nobody else in the business uses,
 * and the only way to find out is an agent asking why their slip looks wrong.
 *
 * It was hard-coded as "Tier 1/2/3" in two forms before this, which meant
 * matching the CRM needed a release.
 */
class CommissionScheme extends Model
{
    protected $fillable = ['name', 'crm_key', 'description', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * Employees on this scheme.
     *
     * Matched by name rather than by id: the employee record has carried a
     * scheme name since before this table existed, and rewriting live payroll
     * records to hold an id buys nothing the name does not already give.
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'commission_scheme', 'name');
    }

    /** What the CRM should be asked about, falling back to the name. */
    public function crmName(): string
    {
        return $this->crm_key ?: $this->name;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /** The names an employee may be put on, newest setup first. */
    public static function options(): array
    {
        return static::active()->ordered()->pluck('name', 'name')->all();
    }
}
