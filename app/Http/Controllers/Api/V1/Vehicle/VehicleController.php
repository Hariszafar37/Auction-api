<?php

namespace App\Http\Controllers\Api\V1\Vehicle;

use App\Http\Controllers\Controller;
use App\Http\Resources\Vehicle\PublicVehicleResource;
use App\Models\Auction;
use App\Models\Vehicle;
use App\Models\VehicleNotificationSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class VehicleController extends Controller
{
    /**
     * GET /api/v1/vehicles
     * Public paginated inventory listing with filtering support.
     *
     * Only shows publicly-visible vehicles (available + in_auction).
     * Eager-loads activeLot (with auction) to power the "currently in auction" badge.
     * Media collection is loaded via spatie/laravel-medialibrary.
     *
     * Filters (all optional):
     *   search       — partial match on VIN, make, model
     *   make         — exact match (case-insensitive partial)
     *   model        — exact match (case-insensitive partial)
     *   body_type    — enum value
     *   year_min     — integer
     *   year_max     — integer
     *   mileage_max  — integer
     *   status       — available | in_auction
     *   page         — page number (default: 1)
     *   location     — filter by auction location (matches in_auction vehicles only)
     *   per_page     — items per page (max: 50, default: 20)
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 20), 50);

        $query = Vehicle::publiclyVisible()
            ->with([
                'activeLot.auction',
                'media',
            ])
            // Apply filters
            ->when($request->search, fn ($q, $v) => $q->search($v))
            ->when($request->make,   fn ($q, $v) => $q->where('make', 'like', "%{$v}%"))
            ->when($request->model,  fn ($q, $v) => $q->where('model', 'like', "%{$v}%"))
            ->when($request->body_type, fn ($q, $v) => $q->where('body_type', $v))
            ->when($request->year_min,  fn ($q, $v) => $q->where('year', '>=', (int) $v))
            ->when($request->year_max,  fn ($q, $v) => $q->where('year', '<=', (int) $v))
            ->when($request->mileage_max, fn ($q, $v) => $q->where('mileage', '<=', (int) $v))
            // Location: filter to vehicles whose active lot belongs to an auction at this location
            ->when($request->location, fn ($q, $v) =>
                $q->whereHas('activeLot.auction', fn ($aq) => $aq->where('location', $v))
            )
            // When status is provided, override the publiclyVisible scope result
            ->when(
                $request->filled('status') && in_array($request->status, ['available', 'in_auction']),
                fn ($q) => $q->where('status', $request->status),
            )
            ->orderByDesc('created_at');

        $paginated = $query->paginate($perPage)->appends($request->query());

        return $this->success(
            PublicVehicleResource::collection($paginated),
            meta: [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'from'         => $paginated->firstItem(),
                'to'           => $paginated->lastItem(),
            ],
        );
    }

    /**
     * GET /api/v1/vehicles/{vehicle}
     * Full public detail for a single vehicle.
     *
     * Returns 404 if the vehicle is not publicly visible (withdrawn, sold, or deleted).
     * Eager-loads activeLot (with auction) + full media gallery.
     */
    public function show(Vehicle $vehicle): JsonResponse
    {
        if (! in_array($vehicle->status, ['available', 'in_auction'])) {
            return $this->error('Vehicle not found.', 404, 'not_found');
        }

        $vehicle->load(['activeLot.auction', 'media']);

        return $this->success(new PublicVehicleResource($vehicle));
    }

    /**
     * GET /api/v1/vehicles/locations
     * Returns distinct auction locations for vehicles currently in auction (scheduled or live).
     * Used to populate the location filter dropdown on the public inventory page.
     */
    public function locations(): JsonResponse
    {
        $locations = Auction::whereNotNull('location')
            ->whereIn('status', ['scheduled', 'live'])
            ->orderBy('location')
            ->distinct()
            ->pluck('location');

        return $this->success($locations);
    }

    /**
     * POST /api/v1/vehicles/{vehicle}/notify
     * Subscribe the authenticated user to be notified when this vehicle goes to auction.
     *
     * Rules:
     * - Requires authentication.
     * - Only available vehicles can be subscribed to (already in_auction → user can just bid).
     * - Duplicate subscriptions are silently treated as idempotent (return 200).
     */
    public function subscribe(Request $request, Vehicle $vehicle): JsonResponse
    {
        if (! in_array($vehicle->status, ['available', 'in_auction'])) {
            return $this->error('Vehicle not found.', 404, 'not_found');
        }

        if ($vehicle->status === 'in_auction') {
            return $this->error(
                'This vehicle is already in an active auction. View the auction to place your bid.',
                422,
                'already_in_auction',
            );
        }

        /** @var \App\Models\User $user */
        $user = $request->user();

        // Idempotent — if already subscribed, return success without error.
        VehicleNotificationSubscription::firstOrCreate(
            ['vehicle_id' => $vehicle->id, 'user_id' => $user->id],
        );

        return $this->success(
            null,
            "You'll be notified when this vehicle is listed for auction.",
        );
    }
}
