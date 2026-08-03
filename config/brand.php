<?php

use App\Support\Brand;

return [

    /*
    |--------------------------------------------------------------------------
    | Brand Identity
    |--------------------------------------------------------------------------
    |
    | Customer-facing identity, surfaced to Blade via config('brand.*').
    | The names are intentionally NOT env-driven — see App\Support\Brand for
    | why. Only the logo URL and support address are overridable, because
    | those legitimately vary by deployment (CDN host, support inbox).
    |
    */

    'name' => Brand::NAME,

    'legal_name' => Brand::LEGAL_NAME,

    /*
    | Absolute URL of the logo shown in email headers. Leave BRAND_LOGO_URL
    | unset to serve it from this application's own public/ directory.
    */
    'logo_url' => env('BRAND_LOGO_URL'),

    /*
    | Where customers are told to go for help, and the marketing site they
    | land on when clicking the logo in an email.
    */
    'support_email' => env('BRAND_SUPPORT_EMAIL', 'colonialauctionservices@gmail.com'),

    'site_url' => env('FRONTEND_URL', env('APP_URL', 'http://localhost')),

];
