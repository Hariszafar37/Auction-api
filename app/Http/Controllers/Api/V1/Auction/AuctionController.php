<?php

namespace App\Http\Controllers\Api\V1\Auction;

use App\Enums\AuctionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Auction\AuctionResource;
use App\Models\Auction;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class AuctionController extends Controller
{
    /**
     * GET /api/v1/auctions
     * Public — list scheduled and live auctions, filterable by location.
     */
    public function index(Request $request): JsonResponse
    {
        $auctions = QueryBuilder::for(Auction::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::partial('location'),
                AllowedFilter::partial('title'),
            ])
            ->allowedSorts(['starts_at', 'title', 'created_at'])
            ->whereIn('status', ['scheduled', 'live'])
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
     * GET /api/v1/auctions/calendar
     * Public — auctions grouped by date for a given month.
     *
     * Query params:
     *   month    — YYYY-MM (defaults to current month)
     *   location — partial match filter (e.g. "Baltimore")
     */
    public function calendar(Request $request): JsonResponse
    {
        $monthParam = $request->input('month', now()->format('Y-m'));

        try {
            $start = Carbon::createFromFormat('Y-m', $monthParam)->startOfMonth()->startOfDay();
        } catch (InvalidFormatException) {
            return $this->error('Invalid month format. Expected YYYY-MM (e.g. 2026-03).', 422, 'invalid_month');
        }

        $end      = $start->copy()->endOfMonth()->endOfDay();
        $location = $request->input('location');

        // ── Auctions in the requested month ──────────────────────────────────────
        $query = Auction::query()
            ->whereIn('status', [AuctionStatus::Scheduled, AuctionStatus::Live])
            ->whereBetween('starts_at', [$start, $end])
            ->withCount('lots')
            ->orderBy('starts_at');

        if ($location) {
            $query->where('location', 'like', "%{$location}%");
        }

        $monthAuctions = $query->get();

        // ── Live auctions not already in this month's range ───────────────────────
        $liveAuctions = Auction::query()
            ->where('status', AuctionStatus::Live)
            ->whereNotIn('id', $monthAuctions->pluck('id'))
            ->withCount('lots')
            ->orderBy('starts_at')
            ->get();

        // ── Group by local date using each auction's own timezone ─────────────────
        $dates = [];
        foreach ($monthAuctions as $auction) {
            $tz   = $auction->timezone ?? 'UTC';
            $date = $auction->starts_at->timezone($tz)->format('Y-m-d');
            $dates[$date][] = $this->formatCalendarEntry($auction);
        }
        ksort($dates);

        // ── Distinct locations for the filter UI (all public auctions) ────────────
        $locations = Auction::query()
            ->whereIn('status', [AuctionStatus::Scheduled, AuctionStatus::Live])
            ->whereNotNull('location')
            ->orderBy('location')
            ->distinct()
            ->pluck('location')
            ->values();

        return $this->success([
            'month'       => $start->format('Y-m'),
            'month_label' => $start->format('F Y'),
            'live'        => $liveAuctions->map(fn ($a) => $this->formatCalendarEntry($a))->values(),
            'dates'       => $dates,
            'locations'   => $locations,
            'total'       => $monthAuctions->count(),
        ]);
    }

    private function formatCalendarEntry(Auction $auction): array
    {
        $tz = $auction->timezone ?? 'UTC';

        return [
            'id'           => $auction->id,
            'title'        => $auction->title,
            'location'     => $auction->location,
            'timezone'     => $auction->timezone,
            'day_of_week'  => $auction->starts_at->timezone($tz)->format('l'),
            'date'         => $auction->starts_at->timezone($tz)->format('Y-m-d'),
            'time'         => $auction->starts_at->timezone($tz)->format('g:i A'),
            'starts_at'    => $auction->starts_at->toIso8601String(),
            'ends_at'      => $auction->ends_at?->toIso8601String(),
            'status'       => $auction->status->value,
            'status_label' => $auction->status->label(),
            'lot_count'    => $auction->lots_count,
        ];
    }

    /**
     * GET /api/v1/auctions/{auction}
     * Public — auction detail with lots.
     */
    public function show(Auction $auction): JsonResponse
    {
        return $this->success(
            new AuctionResource($auction->load(['lots.vehicle.media']))
        );
    }

    /**
     * GET /api/v1/auctions/{auction}/lots
     * Public — paginated lots for a single auction.
     */
    public function lots(Request $request, Auction $auction): JsonResponse
    {
        $lots = QueryBuilder::for($auction->lots()->getQuery())
            ->allowedFilters([AllowedFilter::exact('status')])
            ->allowedSorts(['lot_number', 'current_bid', 'status'])
            ->with('vehicle.media')
            ->paginate($request->integer('per_page', 20))
            ->appends($request->query());

        return $this->success(
            \App\Http\Resources\Auction\AuctionLotResource::collection($lots),
            meta: [
                'current_page' => $lots->currentPage(),
                'last_page'    => $lots->lastPage(),
                'per_page'     => $lots->perPage(),
                'total'        => $lots->total(),
            ]
        );
    }

    /**
     * GET /api/v1/auctions/{auction}/lots/{lot}
     * Public — single lot detail.
     */
    public function showLot(Auction $auction, \App\Models\AuctionLot $lot): JsonResponse
    {
        if ($lot->auction_id !== $auction->id) {
            return $this->error('Lot does not belong to this auction.', 404, 'not_found');
        }

        return $this->success(
            new \App\Http\Resources\Auction\AuctionLotResource($lot->load('vehicle.media'))
        );
    }
}
