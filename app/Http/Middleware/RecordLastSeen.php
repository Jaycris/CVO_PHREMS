<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecordLastSeen
{
    /**
     * How stale the stamp is allowed to get before it is written again.
     *
     * Every request would mean a write on every page load, every Livewire
     * poll and every keystroke in a live-bound field — for a figure nobody
     * reads to the second. A minute is finer than the five-minute window that
     * decides "online", so the answer is never wrong because of this.
     */
    protected const REFRESH_AFTER_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        /*
         * The user is already loaded by the auth guard, so the check costs
         * nothing — no extra read, and a write at most once a minute.
         *
         * Saved with timestamps off: touching updated_at here would make every
         * page load look like an edit to the account, and "last changed" is a
         * different question from "last seen".
         */
        if ($user && $this->isStale($user->last_seen_at)) {
            $user->forceFill(['last_seen_at' => now()])->saveQuietly(['timestamps' => false]);
        }

        return $next($request);
    }

    protected function isStale(mixed $lastSeen): bool
    {
        return $lastSeen === null
            || $lastSeen->lt(now()->subSeconds(self::REFRESH_AFTER_SECONDS));
    }
}
