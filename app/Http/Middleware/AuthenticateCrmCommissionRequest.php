<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AuthenticateCrmCommissionRequest
{
    public function handle(Request $request, Closure $next)
    {
        $configured = (string) config('services.crm.commission_release_token');
        $provided = (string) $request->bearerToken();
        abort_unless($configured !== '' && hash_equals($configured, $provided), 401, 'Bad or missing commission lookup token.');
        return $next($request);
    }
}
