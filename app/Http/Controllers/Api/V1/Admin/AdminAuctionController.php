<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\CreateAuctionRequest;
use App\Http\Requests\Auction\UpdateAuctionRequest;
use App\Http\Resources\Auction\AuctionResource;
use App\Models\Auction;
use App\Services\Auction\AuctionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class AdminAuctionController extends Controller
{
    public function __construct(
        private readonly AuctionService $auctionService,
    ) {}

    /**
     * GET /api/v1/admin/auctions
     */
    public function index(Request $request): JsonResponse
    {
        $auctions = QueryBuilder::for(Auction::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::partial('location'),
                AllowedFilter::partial('title'),
            ])
            ->allowedSorts(['starts_at', 'title', 'created_at', 'status'])
            ->withCount('lots')
            ->paginate($request->integer('per_page', 20))
            ->appends($request->query());

        return $this->success(
            AuctionResource::collection($auctions),
            meta: [
                'current_page' => $auctions->currentPage(),
                'last_page'    => $auctions->lastPage(),
                'per_page'     => $auctions->perPage(),
                'total'        => $auctions->total(),
            ]
        );
    }

    /**
     * POST /api/v1/admin/auctions
     */
    public function store(CreateAuctionRequest $request): JsonResponse
    {
        $auction = $this->auctionService->createAuction(
            $request->validated(),
            $request->user()
        );

        return $this->success(new AuctionResource($auction), 'Auction created.', 201);
    }

    /**
     * GET /api/v1/admin/auctions/{auction}
     */
    public function show(Auction $auction): JsonResponse
    {
        return $this->success(
            new AuctionResource($auction->load(['lots.vehicle', 'creator']))
        );
    }

    /**
     * PATCH /api/v1/admin/auctions/{auction}
     */
    public function update(UpdateAuctionRequest $request, Auction $auction): JsonResponse
    {
        try {
            $auction = $this->auctionService->updateAuction($auction, $request->validated());
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, 'update_failed', $e->errors());
        }

        return $this->success(new AuctionResource($auction), 'Auction updated.');
    }

    /**
     * POST /api/v1/admin/auctions/{auction}/publish
     * Draft → Scheduled
     */
    public function publish(Auction $auction): JsonResponse
    {
        try {
            $auction = $this->auctionService->publishAuction($auction);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, 'transition_failed', $e->errors());
        }

        return $this->success(new AuctionResource($auction), 'Auction published and scheduled.');
    }

    /**
     * POST /api/v1/admin/auctions/{auction}/start
     * Scheduled → Live
     */
    public function start(Auction $auction): JsonResponse
    {
        try {
            $auction = $this->auctionService->startAuction($auction);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, 'transition_failed', $e->errors());
        }

        return $this->success(new AuctionResource($auction), 'Auction is now live.');
    }

    /**
     * POST /api/v1/admin/auctions/{auction}/end
     * Live → Ended
     */
    public function end(Auction $auction): JsonResponse
    {
        try {
            $auction = $this->auctionService->endAuction($auction);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, 'transition_failed', $e->errors());
        }

        return $this->success(new AuctionResource($auction), 'Auction ended.');
    }

    /**
     * POST /api/v1/admin/auctions/{auction}/cancel
     */
    public function cancel(Auction $auction): JsonResponse
    {
        try {
            $auction = $this->auctionService->cancelAuction($auction);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, 'transition_failed', $e->errors());
        }

        return $this->success(new AuctionResource($auction), 'Auction cancelled.');
    }
}
