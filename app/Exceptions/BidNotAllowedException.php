<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thrown by BiddingService when a user fails the unified bid-eligibility
 * check (User::canBid). Replaces the old PaymentRequiredException with a
 * structured, reason-carrying envelope so the frontend can route the user
 * to the correct remediation (payment page, support message, etc.).
 *
 * The reason strings are a closed enum — the frontend switches on them.
 * Adding a new reason is a coordinated FE+BE change.
 *
 * Reasons currently emitted:
 *   - missing_payment    — no non-expired card on file
 *   - inactive_account   — status !== 'active' (excluding the suspended case)
 *   - suspended          — status === 'suspended'
 *
 * Future reasons (reserved, not yet wired):
 *   - deposit_required   — insufficient deposit for the lot
 *   - credit_exhausted   — credit-limit exceeded
 *   - kyc_required       — KYC step outstanding
 *
 * Rendered as HTTP 403 Forbidden — "you lack the entitlement to perform
 * this action right now". 402 was the old payment-only code; we intentionally
 * move to 403 so the frontend gates on `code` rather than HTTP status.
 */
class BidNotAllowedException extends Exception
{
    public function __construct(
        private readonly string $reason = 'not_eligible',
        string $message = 'You are not eligible to place a bid.',
    ) {
        parent::__construct($message);
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'code'    => 'BID_NOT_ALLOWED',
            'reason'  => $this->reason,
        ], 403);
    }
}
