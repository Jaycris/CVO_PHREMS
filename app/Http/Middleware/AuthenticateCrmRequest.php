<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the endpoints the CRM calls into.
 *
 * Two ways in, on purpose:
 *
 *   A token issued on the System Settings screen. This is the normal path — an
 *   admin can issue one, see when it was last used, and revoke it without
 *   touching a server.
 *
 *   CRM_INBOUND_API_TOKEN from the environment. Kept so an install configured
 *   before the screen existed keeps working, and so there is a way in if the
 *   database is the thing that is broken.
 *
 * Either way the secret is separate from the one this app uses to call the CRM.
 * They travel in opposite directions and one leaking should not surrender both.
 */
class AuthenticateCrmRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $presented = $request->bearerToken()
            ?: $request->header('X-HRIS-Token')
            ?: $request->header('X-CRM-Token');

        if (! is_string($presented) || $presented === '') {
            return $this->refuse($request, 'No token presented.');
        }

        if ($token = ApiToken::findByPlaintext($presented)) {
            $token->touchUsage();

            return $next($request);
        }

        $fromEnv = (string) config('services.crm.inbound_token');

        // Constant-time compare: a plain !== leaks the secret one character at
        // a time to anyone patient enough to measure the response. The length
        // check guards the empty case — an unset env var must never match an
        // empty header and let the whole staff directory out.
        if ($fromEnv !== '' && hash_equals($fromEnv, $presented)) {
            return $next($request);
        }

        return $this->refuse($request, 'Bad token.');
    }

    protected function refuse(Request $request, string $reason): Response
    {
        Log::warning('CRM lookup refused', [
            'ip' => $request->ip(),
            'path' => $request->path(),
            'reason' => $reason,
        ]);

        // The reason is logged, not returned. Telling a caller which half of the
        // check failed is telling them how to get closer.
        return response()->json([
            'error' => 'unauthorised',
            'message' => 'Bad or missing token.',
        ], 401);
    }
}
