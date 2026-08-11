<?php

namespace App\Http\Middleware;

use App\Support\SpamPurgeAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts the spam purge console to the operators named in
 * config('bot_guard.purge_allowlist').
 *
 * Layered on top of `role:admin` and `permission:users.purge` rather than
 * replacing them — this endpoint permanently deletes user accounts, and the
 * one-off cleanup it was built for is done, so it should now be reachable by as
 * few people as possible.
 *
 * Denials are logged. An administrator hitting this is either a stale bookmark
 * or someone probing, and both are worth being able to see after the fact.
 */
class EnsureCanPurgeSpam
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (SpamPurgeAccess::allows($user)) {
            return $next($request);
        }

        Log::warning('spam purge console: access denied', [
            'user_id' => $user?->id,
            'email'   => $user?->email,
            'path'    => $request->path(),
            'ip'      => $request->ip(),
        ]);

        // 404 rather than 403: an administrator who is not on the allowlist has
        // no need to learn that this console exists at all.
        return response()->json([
            'success' => false,
            'message' => 'Resource not found.',
            'code'    => 'not_found',
        ], 404);
    }
}
