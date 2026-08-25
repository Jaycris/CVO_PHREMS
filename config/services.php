<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | CRM
    |--------------------------------------------------------------------------
    |
    | The CRM owns commission figures outright. This app reads them and shows
    | them; it never works one out for itself, because two systems computing the
    | same peso from different rules is how they end up disagreeing in front of
    | an agent.
    |
    | auth_header picks how the token is sent — 'bearer' for a standard
    | Authorization header, 'x-hris-token' for the custom one. Whichever the CRM
    | actually reads.
    |
    */
    'crm' => [
        'base_url' => env('CRM_API_BASE_URL'),
        'token' => env('CRM_HRIS_API_TOKEN'),
        'auth_header' => env('CRM_HRIS_AUTH_HEADER', 'bearer'),
        'timeout' => (int) env('CRM_API_TIMEOUT', 15),
        // Commission figures move during the month, so this is short. It only
        // exists to stop a page refresh hammering the CRM.
        'cache_ttl' => (int) env('CRM_API_CACHE_TTL', 300),

        // The agent list is held far more briefly than a slip. Slip figures are
        // a month's sales and barely move; this is setup somebody changes by
        // hand in the CRM and then checks in PHREMS moments later.
        'agent_cache_ttl' => (int) env('CRM_AGENT_CACHE_TTL', 60),
        'verify_tls' => filter_var(env('CRM_API_VERIFY_TLS', true), FILTER_VALIDATE_BOOLEAN),

        /*
         * The other direction: what the CRM presents when it calls this app's
         * employee lookup. A different secret from the one above on purpose —
         * they travel opposite ways and one leaking should not surrender both.
         *
         * Blank closes the lookup API entirely rather than opening it.
         */
        'inbound_token' => env('CRM_INBOUND_API_TOKEN'),
    ],

];
