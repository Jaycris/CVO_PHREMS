<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Office addresses
    |--------------------------------------------------------------------------
    |
    | Addresses that count as being in the office, set here rather than on the
    | Office Networks page. Comma separated; a single address or a range:
    |
    |   OFFICE_IP_ADDRESSES="203.0.113.5,203.0.113.0/24"
    |
    | These are checked alongside whatever is on the page, and exist because
    | anything typed into the app can also be deleted from inside the app. If
    | somebody removes the wrong row and the office cannot clock in, an address
    | set here still lets them, and it can only be changed by editing .env on
    | the server.
    |
    | Leave it empty to manage the list entirely from the page.
    |
    */

    'office_ips' => env('OFFICE_IP_ADDRESSES', ''),

];
