<?php

use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort(),
        // Sanctum::currentRequestHost(),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. This will override any values set in the token's
    | "expires_at" attribute, but first-party sessions are not affected.
    |
    | Deliberately left null — see 'token_lifetime' below. This global value is
    | measured from the token's created_at and applies identically to every
    | token, so it can neither vary by role nor slide forward on activity.
    | Sanctum's guard ANDs this check with the per-token expires_at check
    | (Guard::isValidAccessToken), so keeping this null leaves the per-token
    | expires_at as the single authority.
    |
    */

    'expiration' => null,

    /*
    |--------------------------------------------------------------------------
    | Token Lifetime (per-role, sliding)
    |--------------------------------------------------------------------------
    |
    | Each token is issued with its own expires_at (AuthController@login) and
    | that timestamp is pushed forward on every authenticated request by the
    | SlidingTokenExpiration middleware. The result is an IDLE timeout: the
    | session only dies after a genuine period of inactivity, so an admin is
    | not logged out mid-task and a bidder is not dropped mid-auction.
    |
    | Admin and staff hold back-office privileges and so get the shorter
    | window; every other role (buyer / dealer / seller) gets the standard one.
    |
    */

    'token_lifetime' => [

        // Holding ANY of these roles selects the privileged (shorter) window.
        'privileged_roles' => ['admin', 'staff'],

        'privileged_minutes' => (int) env('TOKEN_LIFETIME_PRIVILEGED_MINUTES', 60),

        'standard_minutes' => (int) env('TOKEN_LIFETIME_STANDARD_MINUTES', 180),

        /*
         * Minimum seconds between expires_at writes. Without this the sliding
         * renewal would issue one extra UPDATE per request; because expires_at
         * only moves forward by the time elapsed since the last slide, skipping
         * sub-threshold moves caps the cost at one write per token per window.
         * The trade-off is that the idle timeout can fire up to this many
         * seconds early — negligible against a 60/180 minute window.
         */
        'slide_throttle_seconds' => (int) env('TOKEN_SLIDE_THROTTLE_SECONDS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of numerous
    | security scanning initiatives maintained by open source platforms
    | that notify developers if they commit tokens into repositories.
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],

];
