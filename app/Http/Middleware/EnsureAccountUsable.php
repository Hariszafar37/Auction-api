<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces account *usability* on every authenticated API request.
 *
 * Login already rejects blocked/suspended users, but a valid Sanctum token
 * issued before the restriction would otherwise keep working until it expires.
 * This middleware closes that gap so an admin action takes effect immediately.
 *
 * Scope is intentionally narrow — it only gates the two "no platform usage"
 * states. Bidding and selling capability remain enforced by their own gates
 * (User::canBid / getBidIneligibilityReason and canPerformSellerActions), so a
 * user with bidding/selling disabled can still browse, view history, and pay.
 *
 *   - blocked   → hard lockout: revoke all tokens, then 403 account_blocked
 *   - suspended → 403 account_suspended (token left intact; softer, reversible)
 */
class EnsureAccountUsable
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            if ($user->isBlocked()) {
                // Hard lockout — invalidate every active session immediately.
                $user->tokens()->delete();

                return response()->json([
                    'success' => false,
                    'message' => 'Your account has been blocked. Please contact support.',
                    'code'    => 'account_blocked',
                ], 403);
            }

            if ($user->isSuspended()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your account has been suspended. Please contact support.',
                    'code'    => 'account_suspended',
                ], 403);
            }
        }

        return $next($request);
    }
}
