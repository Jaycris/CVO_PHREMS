<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * An address, or range of addresses, that counts as being in the office.
 *
 * Used to stop an on-site employee clocking in from home. It is a weak control
 * and worth being honest about: anyone determined can reach the office network
 * from elsewhere. What it does stop is the easy version — punching in from bed
 * — which is the version that actually happens.
 */
class OfficeNetwork extends Model
{
    protected $fillable = ['label', 'ip_address', 'is_active', 'note'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Whether an address falls inside this entry. */
    public function matches(?string $ip): bool
    {
        if (blank($ip)) {
            return false;
        }

        // Handles a plain address and a CIDR range with the same call, and
        // knows the difference between IPv4 and IPv6 — which matters because a
        // browser on a modern home connection often reports IPv6 while the
        // office line is recorded as IPv4.
        return IpUtils::checkIp($ip, $this->ip_address);
    }

    /**
     * Whether an address is inside any office network.
     *
     * Two sources, and the config one exists for a reason worth stating: a
     * database row can be edited, deactivated or deleted by anyone with the
     * page open, and getting it wrong stops a shift starting. An address set
     * in .env cannot be changed from inside the app at all, so it is the way
     * back in when the list has been broken.
     *
     * An empty list means nobody is inside the office, which is why the caller
     * decides what that means rather than this deciding for it.
     */
    public static function contains(?string $ip): bool
    {
        if (blank($ip)) {
            return false;
        }

        foreach (static::fromConfig() as $range) {
            if (IpUtils::checkIp($ip, $range)) {
                return true;
            }
        }

        return static::active()->get()->contains(fn (self $network) => $network->matches($ip));
    }

    /**
     * Addresses set in .env rather than on the page.
     *
     * Comma separated, single addresses or ranges:
     *   OFFICE_IP_ADDRESSES="203.0.113.5,203.0.113.0/24"
     *
     * Anything malformed is dropped rather than thrown, because a typo here
     * must not take the attendance page down for everybody.
     *
     * @return list<string>
     */
    public static function fromConfig(): array
    {
        return collect(explode(',', (string) config('attendance.office_ips')))
            ->map(fn (string $value) => trim($value))
            ->filter(fn (string $value) => $value !== '' && static::isValidAddress($value))
            ->values()
            ->all();
    }

    /** Whether anything at all counts as the office, from either source. */
    public static function anyConfigured(): bool
    {
        return static::fromConfig() !== [] || static::active()->exists();
    }

    /**
     * Whether a written address is usable.
     *
     * Checked before saving, because an entry nobody notices is malformed is
     * an entry that silently matches nothing — and the symptom of that is an
     * employee who cannot clock in, not an error anyone sees.
     */
    public static function isValidAddress(string $value): bool
    {
        $value = trim($value);

        if ($value === '') {
            return false;
        }

        if (! str_contains($value, '/')) {
            return filter_var($value, FILTER_VALIDATE_IP) !== false;
        }

        [$address, $prefix] = explode('/', $value, 2);

        if (filter_var($address, FILTER_VALIDATE_IP) === false || ! ctype_digit($prefix)) {
            return false;
        }

        $max = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 32 : 128;

        return (int) $prefix >= 0 && (int) $prefix <= $max;
    }
}
