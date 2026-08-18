<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the endpoints the CRM calls into.
 *
 * A separate secret from the one this app uses to call the CRM. They travel in
 * opposite directions and one being exposed should not hand over the other.
 */
class AuthenticateCrmRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.crm.inbound_token');

        // Refusing everything is the right answer when no secret is configured.
        // The alternative — an empty string matching an empty header — would
        // publish the staff directory to anyone who found the URL.
        if ($expected === '') {
            return response()->json([
                'error' => 'not_configured',
                'message' => 'This HRIS has no CRM_INBOUND_API_TOKEN set, so the lookup API is closed.',
            ], 503);
        }

        $presented = $request->bearerToken()
            ?: $request->header('X-HRIS-Token')
            ?: $request->header('X-CRM-Token');

        // Constant-time compare: a plain !== leaks the secret one character at
        // a time to anyone patient enough to measure the response.
        if (! is_string($presented) || ! hash_equals($expected, $presented)) {
            Log::warning('CRM lookup refused', ['ip' => $request->ip(), 'path' => $request->path()]);

            return response()->json([
                'error' => 'unauthorised',
                'message' => 'Bad or missing token.',
            ], 401);
        }

        return $next($request);
    }
}
