<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps PHREMS out of search results.
 *
 * There is nothing here the public should find. The login page names the
 * company and the software; a payroll system answering to a search for
 * "CreatiVision payroll" is an invitation to try passwords against it, and the
 * onboarding and password-setup links are signed URLs that have no business
 * being crawled and cached at all.
 *
 * Sent as a header rather than only as a meta tag, so it also covers redirects,
 * file downloads and the API — none of which have a <head> to put a tag in.
 *
 * Note the deliberate absence of a Disallow in robots.txt. Blocking the crawl
 * would stop a search engine ever fetching the page, and a page it cannot fetch
 * is a page whose noindex it never sees — which is how a site stays listed for
 * years after being "blocked". Let them in; tell them not to keep it.
 */
class PreventSearchIndexing
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, noimageindex');

        return $response;
    }
}
