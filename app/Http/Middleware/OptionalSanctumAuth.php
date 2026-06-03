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
                auth('sanctum')->authenticate();
            } catch (\Throwable) {
                // Invalid or expired token — continue as guest
            }
        }

        return $next($request);
    }
}
