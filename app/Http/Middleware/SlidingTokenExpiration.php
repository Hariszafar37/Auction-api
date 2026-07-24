<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the caller's Sanctum token alive for as long as they stay active.
 *
 * Tokens are issued with a per-role expires_at (see User::tokenLifetimeMinutes).
 * On its own that would be a HARD expiry measured from login — an admin would be
 * logged out 60 minutes in even while actively working, and a bidder would drop
 * mid-auction at the 3-hour mark. Pushing expires_at forward on each
 * authenticated request turns it into an IDLE timeout instead: the session only
 * dies after a genuine period of inactivity.
 *
 * Runs in the "after" phase so that auth:sanctum has already resolved the user,
 * and so the refreshed expiry can be attached to the outgoing response for the
 * frontend's pre-expiry warning.
 */
class SlidingTokenExpiration
{
    /** Response header carrying the refreshed expiry (ISO-8601). */
    public const HEADER = 'X-Token-Expires-At';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();

        if (! $user) {
            return $response;
        }

        $token = $user->currentAccessToken();

        // Not a bearer-token request (e.g. a first-party session, or a test
        // using actingAs() with a TransientToken) — there is nothing to slide.
        if (! $token instanceof PersonalAccessToken) {
            return $response;
        }

        // The logout endpoint deletes the current token, but the model instance
        // survives in memory with exists = false — and save() on such a model
        // INSERTs rather than UPDATEs, which would resurrect the row and
        // silently undo the logout. (A mass delete via $user->tokens()->delete()
        // leaves exists = true, but then save() issues an UPDATE that matches
        // zero rows, so that path is already safe.)
        if (! $token->exists) {
            return $response;
        }

        $expiresAt = $user->tokenExpiresAt();
        $throttle  = (int) config('sanctum.token_lifetime.slide_throttle_seconds', 60);

        // expires_at only moves forward by the time elapsed since the last
        // slide, so skipping sub-threshold moves caps this at one extra UPDATE
        // per token per throttle window rather than one per request.
        if (! $token->expires_at || $token->expires_at->diffInSeconds($expiresAt, absolute: true) >= $throttle) {
            $token->forceFill(['expires_at' => $expiresAt])->save();
        } else {
            // Report the stored value, not the un-written one, so the frontend
            // countdown never shows time the server would not actually honour.
            $expiresAt = $token->expires_at;
        }

        // Lets the frontend render its "session expiring" warning without
        // polling a dedicated endpoint. Must be listed in config/cors.php
        // `exposed_headers` or the browser will not let JS read it.
        $response->headers->set(self::HEADER, $expiresAt->toIso8601String());

        return $response;
    }
}
