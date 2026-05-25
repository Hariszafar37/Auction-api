<?php

namespace App\Http\Controllers\Api\V1\Dealer;

use App\Enums\LotStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Dealer\DealerLotResource;
use App\Models\AuctionLot;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DealerDashboardController extends Controller
{
    /** Valid lot status values — used to reject bogus filter params. */
    private static function validStatuses(): array
    {
        return array_column(LotStatus::cases(), 'value');
    }

    /**
     * GET /api/v1/my/dealer/dashboard
     * Aggregate KPI stats for the authenticated dealer.
     * Uses two consolidated queries instead of six to minimise round-trips.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();

        // ── Vehicle counts — one grouped query ──────────────────────────────────
        $byStatus = Vehicle::where('seller_id', $user->id)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        // ── Lot stats + total revenue — one aggregate query ─────────────────────
        // CASE WHEN is ANSI SQL and works on both MySQL (production) and
        // SQLite (test suite). COALESCE guards against NULL sold_price values.
        $lotAgg = AuctionLot::whereHas('vehicle', fn ($q) => $q->where('seller_id', $user->id))
            ->selectRaw(implode(', ', [
                'COUNT(*) as total',
                "SUM(CASE WHEN status IN ('open','countdown','if_sale') THEN 1 ELSE 0 END) as active",
                "SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as sold_count",
                "SUM(CASE WHEN status IN ('reserve_not_met','no_sale','cancelled') THEN 1 ELSE 0 END) as unsold",
                "SUM(CASE WHEN status = 'sold' THEN COALESCE(sold_price, 0) ELSE 0 END) as total_revenue",
            ]))
            ->first();

        // ── Monthly revenue — separate query (different WHERE predicate) ─────────
        $thisMonthRevenue = AuctionLot::whereHas('vehicle', fn ($q) => $q->where('seller_id', $user->id))
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
                'total_submitted' => (int) ($lotAgg->total       ?? 0),
                'active'          => (int) ($lotAgg->active      ?? 0),
                'sold'            => (int) ($lotAgg->sold_count  ?? 0),
                'unsold'          => (int) ($lotAgg->unsold      ?? 0),
            ],
            'revenue' => [
                'total_sold_value' => (float) ($lotAgg->total_revenue ?? 0),
                'this_month'       => (float) $thisMonthRevenue,
            ],
        ]);
    }

    /**
     * GET /api/v1/my/dealer/lots
     * Paginated lots for vehicles the authenticated dealer has submitted to auction.
     * The optional ?status= param is validated against LotStatus enum values;
     * unrecognised values are silently ignored (no filtering applied).
     */
    public function lots(Request $request): JsonResponse
    {
        $user          = $request->user();
        $validStatuses = self::validStatuses();
        $perPage       = min($request->integer('per_page', 15), 100);

        $query = AuctionLot::whereHas('vehicle', fn ($q) => $q->where('seller_id', $user->id))
            ->with(['vehicle', 'auction'])
            ->when(
                $request->status && in_array($request->status, $validStatuses, true),
                fn ($q) => $q->where('status', $request->status),
            )
            ->when(
                $request->filled('auction_id') && is_numeric($request->auction_id),
                fn ($q) => $q->where('auction_id', (int) $request->auction_id),
            )
            ->orderByDesc('created_at');

        $paginated = $query->paginate($perPage);

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
