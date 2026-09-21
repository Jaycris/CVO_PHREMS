<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * PHREMS switched off for everybody except the people allowed to switch it on.
 *
 * A setting rather than `php artisan down`, so the CEO or COO can do it from
 * System Settings without a terminal, and keep working while everyone else sees
 * the maintenance page. `artisan down` still works for the times the app itself
 * is broken, and shows the same page.
 *
 * The page is resources/views/maintenance.blade.php, which the frontend owns.
 * It is handed two optional values — see viewData().
 */
class MaintenanceMode
{
    public const PERMISSION = 'app.maintenance.manage';

    public const GROUP = 'Maintenance';

    public const ENABLED = 'maintenance_mode';

    public const MESSAGE = 'maintenance_message';

    public const STARTED_AT = 'maintenance_started_at';

    public const STARTED_BY = 'maintenance_started_by';

    /** Told to browsers and crawlers: this is temporary, try again in five minutes. */
    public const RETRY_AFTER_SECONDS = 300;

    public static function isOn(): bool
    {
        return AppSetting::flag(self::ENABLED);
    }

    /** The note written when it was switched on, if any. */
    public static function message(): ?string
    {
        $message = trim((string) AppSetting::get(self::MESSAGE, ''));

        return $message === '' ? null : $message;
    }

    public static function startedAt(): ?Carbon
    {
        $value = AppSetting::get(self::STARTED_AT);

        return $value ? Carbon::parse($value) : null;
    }

    public static function startedBy(): ?string
    {
        return AppSetting::get(self::STARTED_BY);
    }

    /** Whoever may switch it on keeps using PHREMS while it is on. */
    public static function canBypass(?User $user): bool
    {
        return $user !== null && $user->can(self::PERMISSION);
    }

    /**
     * Also how the note is changed while it is on — in which case when it
     * started, and who started it, are left as they were.
     */
    public static function turnOn(User $by, ?string $message = null): void
    {
        $alreadyOn = self::isOn();

        self::write(self::ENABLED, '1', 'Maintenance mode');
        self::write(self::MESSAGE, filled($message) ? trim($message) : null, 'Maintenance note');

        if (! $alreadyOn) {
            self::write(self::STARTED_AT, now()->toIso8601String(), 'Maintenance started at');
            self::write(self::STARTED_BY, $by->name, 'Maintenance started by');
        }
    }

    /** The note is kept, so switching it on again next time starts from it. */
    public static function turnOff(): void
    {
        self::write(self::ENABLED, '0', 'Maintenance mode');
        self::write(self::STARTED_AT, null, 'Maintenance started at');
        self::write(self::STARTED_BY, null, 'Maintenance started by');
    }

    /**
     * What the maintenance page is given. Both may be null, and the page is
     * also rendered by `artisan down` with neither, so it must cope without.
     *
     * @return array{maintenanceMessage: ?string, maintenanceSince: ?Carbon}
     */
    public static function viewData(): array
    {
        return [
            'maintenanceMessage' => self::message(),
            'maintenanceSince' => self::startedAt(),
        ];
    }

    public static function response(Request $request): Response
    {
        $retry = ['Retry-After' => (string) self::RETRY_AFTER_SECONDS];

        // Livewire shows whatever HTML comes back, so it gets the page too.
        if ($request->expectsJson() && ! $request->hasHeader('X-Livewire')) {
            return response()->json(
                ['message' => self::message() ?? 'PHREMS is down for maintenance. Please try again shortly.'],
                503,
                $retry,
            );
        }

        return response()->view('maintenance', self::viewData(), 503, $retry);
    }

    /**
     * Grouped apart from the settings on the general list, which anybody with
     * app.settings.manage can save — this one is only for the CEO or COO.
     */
    protected static function write(string $key, ?string $value, string $label): void
    {
        AppSetting::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'label' => $label, 'group' => self::GROUP, 'type' => 'text'],
        );
    }
}
