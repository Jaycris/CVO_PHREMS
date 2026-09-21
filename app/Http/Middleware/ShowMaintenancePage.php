<?php

namespace App\Http\Middleware;

use App\Services\MaintenanceMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shows the maintenance page to everybody PHREMS is switched off for.
 *
 * On the web group only, so the CRM's API keeps answering — the CRM is a
 * separate system that should not break because PHREMS is being worked on.
 */
class ShowMaintenancePage
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! MaintenanceMode::isOn() || MaintenanceMode::canBypass($request->user())) {
            return $next($request);
        }

        /*
         * Signing in has to keep working, or nobody allowed through could get
         * back in to switch it off. The login form submits through Livewire's
         * routes, so those stay open too — but only to somebody not signed in,
         * which is what stops staff carrying on in a page they already had open.
         */
        if (! $request->user() && ($request->is('/') || $request->routeIs('login', '*livewire.*'))) {
            return $next($request);
        }

        return MaintenanceMode::response($request);
    }
}
