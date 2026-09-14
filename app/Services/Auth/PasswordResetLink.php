<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * A signed, single-use link for choosing a new password.
 *
 * Single-use is the part a plain signed URL does not give you. A signature only
 * says "this app issued this, and it has not expired yet" — the same link works
 * again and again until it does. So the signature carries a fingerprint of the
 * password it was issued against, and setting a new one changes the
 * fingerprint, which retires every link issued before it.
 *
 * That matters because this link arrives in a mailbox and sits there. A
 * forwarded email, a shared laptop, a mailbox somebody still has access to
 * after leaving — any of those is a way back into an account weeks later if the
 * link never stops working.
 *
 * An hour, not the three days an invitation gets. An invitation waits for
 * somebody's first day; a reset is for somebody locked out right now, and every
 * extra hour is an hour the link is worth stealing.
 */
class PasswordResetLink
{
    public const VALID_FOR_MINUTES = 60;

    public static function for(User $user): string
    {
        return URL::temporarySignedRoute(
            'password.reset',
            now()->addMinutes(self::VALID_FOR_MINUTES),
            ['user' => $user->id, 'fp' => self::fingerprint($user)],
        );
    }

    /** Whether this link still matches the account it was issued for. */
    public static function matches(User $user, ?string $fingerprint): bool
    {
        return $fingerprint !== null
            && hash_equals(self::fingerprint($user), $fingerprint);
    }

    /**
     * Derived from the current password, so changing it invalidates the link.
     *
     * The hash itself never leaves the server — only this digest of it does,
     * and a digest of a bcrypt hash gives an attacker nothing to work back
     * from. Keyed with the app secret so it cannot be computed elsewhere.
     */
    protected static function fingerprint(User $user): string
    {
        return hash_hmac(
            'sha256',
            $user->id . '|' . $user->password . '|' . $user->password_set_at?->timestamp,
            (string) config('app.key'),
        );
    }
}
