<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class PayrollSetting extends Model
{
    protected $fillable = ['key', 'value', 'label', 'description', 'type', 'group'];

    /**
     * Settings are read many times inside a single payroll run — once per
     * employee per line — so the whole table is cached as one map and the cache
     * is dropped whenever a row changes.
     */
    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('payroll_settings'));
        static::deleted(fn () => Cache::forget('payroll_settings'));
    }

    /** @return array<string, string|null> */
    public static function all_(): array
    {
        return Cache::rememberForever('payroll_settings', fn () => static::query()->pluck('value', 'key')->all());
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::all_()[$key] ?? $default;
    }

    public static function number(string $key, float $default = 0): float
    {
        $value = static::get($key);

        return $value === null || $value === '' ? $default : (float) $value;
    }

    public static function flag(string $key, bool $default = false): bool
    {
        $value = static::get($key);

        return $value === null || $value === '' ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function castValue(): mixed
    {
        return match ($this->type) {
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $this->value,
            'decimal' => (float) $this->value,
            default => $this->value,
        };
    }
}
