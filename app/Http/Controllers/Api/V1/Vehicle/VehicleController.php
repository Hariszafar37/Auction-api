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
     *   search           — partial match on VIN, make, model
     *   make             — string (partial match) OR array of exact makes (multi-select)
     *   model            — string (partial match) OR array of exact models (multi-select)
     *   body_type        — enum value
     *   year_min         — integer
     *   year_max         — integer
     *   mileage_max      — integer
     *   status           — available | in_auction
     *   page             — page number (default: 1)
     *   location         — filter by auction location (matches in_auction vehicles only)
     *   per_page         — items per page (max: 50, default: 20)
     *   transmission     — case-insensitive partial match
     *   fuel_type        — exact match
     *   drivetrain       — case-insensitive partial match
     *   color            — case-insensitive partial match on exterior color
     *   condition_light  — green | red | blue
     *   sort             — newest (default) | oldest | year_desc | year_asc | mileage_desc | mileage_asc
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 20), 50);

        $query = Vehicle::publiclyVisible($request->user())
            ->with([
                'activeLot.auction',
                'media',
            ])
            // Apply filters
            ->when($request->search, fn ($q, $v) => $q->search($v))
            // Make/Model accept either a single string (partial match) or an array
            // of exact values for multi-select checkbox filtering.
            ->when($request->has('make'),  fn ($q) => $this->applyInOrLike($q, 'make', $request->input('make')))
            ->when($request->has('model'), fn ($q) => $this->applyInOrLike($q, 'model', $request->input('model')))
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
            // Additional filters from reference inventory page
            ->when($request->transmission, fn ($q, $v) => $q->where('transmission', 'like', "%{$v}%"))
            ->when($request->fuel_type,    fn ($q, $v) => $q->where('fuel_type', $v))
            ->when($request->drivetrain,   fn ($q, $v) => $q->where('drivetrain', 'like', "%{$v}%"))
            ->when($request->color,        fn ($q, $v) => $q->where('exterior_color', 'like', "%{$v}%"))
            ->when($request->condition_light && in_array($request->condition_light, ['green', 'red', 'blue']),
                fn ($q) => $q->where('condition_light', $request->condition_light)
            );

        $this->applySort($query, $request->sort);

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
     * Apply a make/model filter that supports both a single string (partial,
     * case-insensitive match) and an array of exact values (multi-select).
     */
    private function applyInOrLike($query, string $column, mixed $value): void
    {
        if (is_array($value)) {
            $values = array_values(array_filter(array_map('trim', $value), fn ($v) => $v !== ''));
            if ($values) {
                $query->whereIn($column, $values);
            }
            return;
        }

        $value = is_string($value) ? trim($value) : $value;
        if ($value !== null && $value !== '') {
            $query->where($column, 'like', "%{$value}%");
        }
    }

    /**
     * Apply a whitelisted sort order to the inventory query.
     */
    private function applySort($query, ?string $sort): void
    {
        $query->reorder();

        match ($sort) {
            'oldest'      => $query->orderBy('created_at'),
            'year_desc'   => $query->orderByDesc('year')->orderByDesc('created_at'),
            'year_asc'    => $query->orderBy('year')->orderByDesc('created_at'),
            'mileage_desc'=> $query->orderByRaw('mileage IS NULL')->orderByDesc('mileage')->orderByDesc('created_at'),
            'mileage_asc' => $query->orderByRaw('mileage IS NULL')->orderBy('mileage')->orderByDesc('created_at'),
            default       => $query->orderByDesc('created_at'), // 'newest'
        };
    }

    /**
     * GET /api/v1/vehicles/facets
     * Returns the distinct makes (each with its models) across the publicly-visible
     * inventory, used to populate the multi-select make/model checkbox filters.
     */
    public function facets(Request $request): JsonResponse
    {
        $rows = Vehicle::publiclyVisible($request->user())
            ->selectRaw('make, model, COUNT(*) as total')
            ->groupBy('make', 'model')
            ->orderBy('make')
            ->orderBy('model')
            ->get();

        $makes = $rows
            ->groupBy('make')
            ->map(fn ($group, $make) => [
                'make'   => $make,
                'count'  => (int) $group->sum('total'),
                'models' => $group
                    ->map(fn ($r) => ['model' => $r->model, 'count' => (int) $r->total])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();

        return $this->success($makes);
    }

    /**
     * GET /api/v1/vehicles/{vehicle}
     * Full public detail for a single vehicle.
     *
     * Returns 404 if the vehicle is not publicly visible (withdrawn, sold, or deleted).
     * Eager-loads activeLot (with auction) + full media gallery.
     */
    public function show(Request $request, Vehicle $vehicle): JsonResponse
    {
        if (! in_array($vehicle->status, ['available', 'in_auction'])) {
            return $this->error('Vehicle not found.', 404, 'not_found');
        }

        $user = $request->user();
        $isPrivileged = $user && ($user->hasRole('dealer') || $user->hasRole('admin'));
        if (! $isPrivileged) {
            $vehicle->load('seller.dealerProfile');
            $sellerProfile = $vehicle->seller?->dealerProfile;
            if ($sellerProfile && in_array($sellerProfile->dealer_classification, ['maryland_wholesale', 'out_of_state_wholesale'], true)) {
                return $this->error('Vehicle not found.', 404, 'not_found');
            }
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
