<?php

namespace App\Http\Controllers\Api\V1\Dealer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Dealer\DealerLotResource;
use App\Models\AuctionLot;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DealerDashboardController extends Controller
{
    /**
     * GET /api/v1/my/dealer/dashboard
     * Aggregate KPI stats for the authenticated dealer.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();

        // ── Vehicle counts by status ────────────────────────────────────────────
        $byStatus = Vehicle::where('seller_id', $user->id)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        // ── Lot stats — all lots submitted by this dealer's vehicles ────────────
        $lotBase = AuctionLot::whereHas('vehicle', fn ($q) => $q->where('seller_id', $user->id));

        $activeLots = (clone $lotBase)
            ->whereIn('status', ['open', 'countdown', 'if_sale'])
            ->count();

        $soldLots = (clone $lotBase)->where('status', 'sold')->count();

        $unsoldLots = (clone $lotBase)
            ->whereIn('status', ['reserve_not_met', 'no_sale', 'cancelled'])
            ->count();

        $totalLots = (clone $lotBase)->count();

        // ── Revenue ─────────────────────────────────────────────────────────────
        $totalRevenue = (clone $lotBase)->where('status', 'sold')->sum('sold_price');

        $thisMonthRevenue = (clone $lotBase)
            ->where('status', 'sold')
            ->whereMonth('closed_at', now()->month)
            ->whereYear('closed_at', now()->year)
            ->sum('sold_price');

        return $this->success([
            'vehicles' => [
                'total'      => (int) $byStatus->sum(),
                'available'  => (int) ($byStatus['available']  ?? 0),
                'in_auction' => (int) ($byStatus['in_auction'] ?? 0),
                'sold'       => (int) ($byStatus['sold']       ?? 0),
                'withdrawn'  => (int) ($byStatus['withdrawn']  ?? 0),
            ],
            'lots' => [
                'total_submitted' => $totalLots,
                'active'          => $activeLots,
                'sold'            => $soldLots,
                'unsold'          => $unsoldLots,
            ],
            'revenue' => [
                'total_sold_value' => (float) $totalRevenue,
                'this_month'       => (float) $thisMonthRevenue,
            ],
        ]);
    }

    /**
     * GET /api/v1/my/dealer/lots
     * Paginated lots for vehicles the authenticated dealer has submitted to auction.
     */
    public function lots(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = AuctionLot::whereHas('vehicle', fn ($q) => $q->where('seller_id', $user->id))
            ->with(['vehicle', 'auction'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at');

        $paginated = $query->paginate(15);

        return response()->json([
            'success' => true,
            'data'    => DealerLotResource::collection($paginated->items()),
            'meta'    => [
                'total'        => $paginated->total(),
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'from'         => $paginated->firstItem(),
                'to'           => $paginated->lastItem(),
            ],
        ]);
    }
}
