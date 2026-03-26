<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\User\WonLotResource;
use App\Models\AuctionLot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WonLotsController extends Controller
{
    /**
     * GET /api/v1/my/won
     *
     * Returns the authenticated user's purchased lots, ordered newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $lots = AuctionLot::query()
            ->where('buyer_id', $request->user()->id)
            ->where('status', 'sold')
            ->with(['vehicle', 'auction'])
            ->orderByDesc('closed_at')
            ->paginate($request->integer('per_page', 20));

        return $this->success(
            WonLotResource::collection($lots),
            meta: [
                'current_page' => $lots->currentPage(),
                'last_page'    => $lots->lastPage(),
                'per_page'     => $lots->perPage(),
                'total'        => $lots->total(),
                'from'         => $lots->firstItem(),
                'to'           => $lots->lastItem(),
            ]
        );
    }
}
