<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AppSetting extends Model
{
    /** The sizes offered on the settings screen. */
    public const ROWS_PER_PAGE_CHOICES = [10, 15, 25, 50, 100];

    protected $fillable = ['key', 'value', 'label', 'description', 'type', 'group'];

    /**
     * Read once per request, then held in memory.
     *
     * Same reason as PayrollSetting: the cache store is the database, so every
     * Cache::get is itself a query. Every paginated table on a page asks for
     * the row count, and some pages have three tables.
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
        Cache::forget('app_settings');
    }

    /** @return array<string, string|null> */
    public static function all_(): array
    {
        return static::$memo ??= Cache::rememberForever(
            'app_settings',
            fn () => static::query()->pluck('value', 'key')->all()
        );
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = static::all_()[$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    /**
     * How many rows every paginated table shows.
     *
     * Clamped to the offered sizes rather than trusted, because a stray value
     * in the database here would otherwise be a page that loads every record
     * in the system.
     */
    public static function rowsPerPage(): int
    {
        $value = (int) static::get('rows_per_page', 10);

        return in_array($value, self::ROWS_PER_PAGE_CHOICES, true) ? $value : 10;
    }
}
