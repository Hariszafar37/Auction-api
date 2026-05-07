<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminBidController extends Controller
{
    /**
     * GET /api/v1/admin/bids
     * Platform-wide bid history for admin audit, with optional filters.
     * Supports: auction_id, lot_id, user_search (name/email), date_from, date_to.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Bid::with([
            'user:id,name,email',
            'auctionLot:id,lot_number,auction_id',
            'auctionLot.auction:id,title,starts_at',
        ])->orderByDesc('placed_at');

        if ($auctionId = $request->integer('auction_id')) {
            $query->whereHas('auctionLot', fn ($q) => $q->where('auction_id', $auctionId));
        }

        if ($lotId = $request->integer('lot_id')) {
            $query->where('auction_lot_id', $lotId);
        }

        if ($search = $request->string('user_search')->toString()) {
            $query->whereHas(
                'user',
                fn ($q) => $q->where('name', 'like', "%{$search}%")
                             ->orWhere('email', 'like', "%{$search}%"),
            );
        }

        if ($dateFrom = $request->string('date_from')->toString()) {
            $query->whereDate('placed_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->string('date_to')->toString()) {
            $query->whereDate('placed_at', '<=', $dateTo);
        }

        $bids = $query->paginate($request->integer('per_page', 30));

        $data = $bids->getCollection()->map(fn ($bid) => [
            'id'         => $bid->id,
            'amount'     => $bid->amount,
            'type'       => $bid->type->value,
            'is_winning' => $bid->is_winning,
            'placed_at'  => $bid->placed_at?->toIso8601String(),
            'user'       => $bid->user ? [
                'id'    => $bid->user->id,
                'name'  => $bid->user->name,
                'email' => $bid->user->email,
            ] : null,
            'lot' => $bid->auctionLot ? [
                'id'         => $bid->auctionLot->id,
                'lot_number' => $bid->auctionLot->lot_number,
                'auction'    => $bid->auctionLot->auction ? [
                    'id'        => $bid->auctionLot->auction->id,
                    'title'     => $bid->auctionLot->auction->title,
                    'starts_at' => $bid->auctionLot->auction->starts_at?->toIso8601String(),
                ] : null,
            ] : null,
        ]);

        return $this->success($data, meta: [
            'current_page' => $bids->currentPage(),
            'last_page'    => $bids->lastPage(),
            'per_page'     => $bids->perPage(),
            'total'        => $bids->total(),
        ]);
    }
}
