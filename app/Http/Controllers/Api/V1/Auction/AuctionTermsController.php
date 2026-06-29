<?php

namespace App\Http\Controllers\Api\V1\Auction;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\AcceptAuctionTermsRequest;
use App\Http\Resources\Auction\AuctionTermResource;
use App\Models\Auction;
use App\Services\Auction\AuctionTermsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuctionTermsController extends Controller
{
    public function __construct(
        private readonly AuctionTermsService $terms,
    ) {}

    /**
     * GET /api/v1/auction-terms/current
     * Public — the live master Terms document (rendered in the entry modal and
     * the "View Full Terms" view).
     */
    public function current(): JsonResponse
    {
        return $this->success(new AuctionTermResource($this->terms->current()));
    }

    /**
     * GET /api/v1/auctions/{auction}/entry-eligibility
     * Authenticated — whether the current user may enter this auction, and if
     * not, why (terms_not_accepted | missing_payment). The frontend gate
     * consumes this to decide which prompt to show.
     */
    public function eligibility(Request $request, Auction $auction): JsonResponse
    {
        return $this->success(
            $this->terms->eligibility($request->user(), $auction)
        );
    }

    /**
     * POST /api/v1/auctions/{auction}/accept-terms
     * Authenticated — record acceptance of the current terms version for this
     * auction. Returns the refreshed eligibility so the caller can proceed
     * straight to the payment check.
     */
    public function accept(AcceptAuctionTermsRequest $request, Auction $auction): JsonResponse
    {
        $this->terms->accept($request->user(), $auction, $request);

        return $this->success(
            $this->terms->eligibility($request->user(), $auction),
            'Auction Terms accepted.'
        );
    }
}
