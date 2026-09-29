<?php

/*
|--------------------------------------------------------------------------
| Cross-origin requests
|--------------------------------------------------------------------------
|
| Only the careers adverts are read from a browser on another origin: the
| company website's Join Our Team page fetches them from this app. Everything
| else under /api is called server to server, where CORS plays no part.
|
| The origins are listed rather than "*", so a page on somebody else's domain
| cannot quietly build itself out of this company's job adverts. Add a site by
| naming it in CAREERS_ALLOWED_ORIGINS, comma separated, scheme and all:
|
|   CAREERS_ALLOWED_ORIGINS=https://creativisionoutsourcing.com,http://localhost:5173
|
*/

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CAREERS_ALLOWED_ORIGINS', 'https://creativisionoutsourcing.com,https://www.creativisionoutsourcing.com')),
)));

return [
    'paths' => ['api/careers/*'],

    // Read-only. The adverts are fetched, never posted to.
    'allowed_methods' => ['GET', 'HEAD', 'OPTIONS'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Content-Type'],

    'exposed_headers' => [],

    'max_age' => 3600,

    // No cookies or credentials — there is nobody to be logged in as.
    'supports_credentials' => false,
];
