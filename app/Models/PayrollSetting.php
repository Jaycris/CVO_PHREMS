<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class PayrollSetting extends Model
{
    protected $fillable = ['key', 'value', 'label', 'description', 'type', 'group'];

    /**
     * Read once per request, then held in memory.
     *
     * The cache alone is not enough: this app's cache store is the database, so
     * every Cache::get is itself a query. A payroll run asks for these settings
     * several times per employee, which turned into hundreds of round trips on
     * a hundred payslips.
     *
     * @var array<string, string|null>|null
     */
    protected static ?array $memo = null;

    protected static function booted(): void
    {
        static::saved(fn () => static::flushCache());
        static::deleted(fn () => static::flushCache());
    }

    public static function flushCache(): void
    {
        static::$memo = null;
        Cache::forget('payroll_settings');
    }

    /** @return array<string, string|null> */
    public static function all_(): array
    {
        return static::$memo ??= Cache::rememberForever(
            'payroll_settings',
            fn () => static::query()->pluck('value', 'key')->all()
        );
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
