<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the Sanctum user from the Bearer token if present, but never fail.
 * Use this on public read-only endpoints that also benefit from knowing the
 * authenticated user (e.g. to show dealer-only lots to dealers).
 */
class OptionalSanctumAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken()) {
            try {
                // Resolve the user from the bearer token on the sanctum guard.
                // If valid, promote sanctum to the request's active guard so that
                // $request->user() — which reads the *default* guard (web) — resolves
                // the authenticated user across controllers and API resources.
                // Without this, token users are seen as guests on these public
                // routes (e.g. my_proxy_max / is_winner come back null/false, and
                // dealer-only lots get filtered out for real dealers).
                if (auth('sanctum')->check()) {
                    auth()->shouldUse('sanctum');
                }
            } catch (\Throwable) {
                // Invalid or expired token — continue as guest
            }
        }

        return $next($request);
    }
}
