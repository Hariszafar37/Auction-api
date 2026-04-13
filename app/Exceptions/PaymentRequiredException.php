<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thrown by BiddingService when a user attempts to place a bid (manual or
 * proxy) without a valid payment method on file. Self-renders to the
 * structured JSON shape the frontend gates on.
 *
 * HTTP 402 Payment Required is the natural fit — and distinct from 403 so
 * the frontend can differentiate account-state issues from payment issues.
 */
class PaymentRequiredException extends Exception
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Valid payment information required before bidding.',
            'code'    => 'PAYMENT_REQUIRED',
        ], 402);
    }
}
