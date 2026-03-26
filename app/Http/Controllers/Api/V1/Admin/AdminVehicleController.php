<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exports\VehicleExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vehicle\UpdateVehicleRequest;
use App\Http\Requests\Vehicle\UpdateVehicleStatusRequest;
use App\Http\Resources\Admin\AdminVehicleResource;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminVehicleController extends Controller
{
    /**
     * GET /api/v1/admin/vehicles
     * Paginated list of vehicles with optional status + search filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Vehicle::with(['seller', 'media'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->search, fn ($q, $term) => $q->search($term))
            ->orderByDesc('created_at');

        $paginated = $query->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => AdminVehicleResource::collection($paginated->items()),
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

    /**
     * POST /api/v1/admin/vehicles
     * Create a new vehicle (admin on behalf of a seller).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'seller_id'       => ['required', 'integer', 'exists:users,id'],
            'vin'             => ['required', 'string', 'size:17', 'unique:vehicles,vin'],
            'year'            => ['required', 'integer', 'min:1900', 'max:' . (date('Y') + 1)],
            'make'            => ['required', 'string', 'max:50'],
            'model'           => ['required', 'string', 'max:50'],
            'trim'            => ['nullable', 'string', 'max:50'],
            'color'           => ['nullable', 'string', 'max:30'],
            'mileage'         => ['nullable', 'integer', 'min:0'],
            'body_type'       => ['required', 'in:car,truck,suv,motorcycle,boat,atv,fleet,other'],
            'transmission'    => ['nullable', 'string', 'max:30'],
            'engine'          => ['nullable', 'string', 'max:50'],
            'fuel_type'       => ['nullable', 'string', 'max:30'],
            'condition_light' => ['required', 'in:green,red,blue'],
            'condition_notes' => ['nullable', 'string', 'max:1000'],
            'has_title'       => ['boolean'],
            'title_state'     => ['nullable', 'string', 'max:5'],
        ]);

        $vehicle = Vehicle::create($data);

        return response()->json([
            'success' => true,
            'data'    => new AdminVehicleResource($vehicle->load(['seller', 'media'])),
            'message' => 'Vehicle created successfully.',
        ], 201);
    }

    /**
     * GET /api/v1/admin/vehicles/{vehicle}
     * Return a single vehicle with seller.
     */
    public function show(Vehicle $vehicle): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => new AdminVehicleResource($vehicle->load(['seller', 'media'])),
        ]);
    }

    /**
     * PATCH /api/v1/admin/vehicles/{vehicle}
     * Update editable vehicle fields.
     *
     * Business rules:
     * - Sold vehicles cannot be edited (they have a finalized auction record).
     * - All other statuses are editable by admin.
     * - VIN uniqueness is validated against all non-deleted records except this vehicle.
     */
    public function update(UpdateVehicleRequest $request, Vehicle $vehicle): JsonResponse
    {
        if ($vehicle->status === 'sold') {
            return $this->error(
                'Sold vehicles cannot be edited. Change the status first if a correction is needed.',
                422,
                'vehicle_sold',
            );
        }

        $vehicle->update($request->validated());

        return $this->success(
            new AdminVehicleResource($vehicle->fresh()->load(['seller', 'media'])),
            'Vehicle updated successfully.',
        );
    }

    /**
     * DELETE /api/v1/admin/vehicles/{vehicle}
     * Soft-delete a vehicle.
     *
     * Business rules:
     * - Vehicles with status in_auction cannot be deleted (they are linked to a live lot).
     * - All other vehicles may be soft-deleted; history is preserved.
     */
    public function destroy(Vehicle $vehicle): JsonResponse
    {
        if ($vehicle->status === 'in_auction') {
            return $this->error(
                'Cannot delete a vehicle that is currently assigned to an active auction lot. Remove the lot first.',
                422,
                'vehicle_in_auction',
            );
        }

        $vehicle->delete();

        return $this->success(null, 'Vehicle deleted successfully.');
    }

    /**
     * PATCH /api/v1/admin/vehicles/{vehicle}/status
     * Manual admin status override.
     *
     * Business rules:
     * - Vehicles that are in_auction and being set back to available require
     *   the active lot to be removed first to preserve auction data integrity.
     * - All other transitions are permitted for admin override.
     */
    public function updateStatus(UpdateVehicleStatusRequest $request, Vehicle $vehicle): JsonResponse
    {
        $newStatus = $request->validated()['status'];

        if ($vehicle->status === $newStatus) {
            return $this->error("Vehicle is already set to '{$newStatus}'.", 422, 'status_unchanged');
        }

        if ($vehicle->status === 'in_auction' && $newStatus === 'available') {
            $hasActiveLot = $vehicle->auctionLots()
                ->whereNotIn('status', ['sold', 'reserve_not_met', 'no_sale', 'cancelled'])
                ->exists();

            if ($hasActiveLot) {
                return $this->error(
                    'Vehicle has an active auction lot. Remove or close the lot before marking it available.',
                    422,
                    'has_active_lot',
                );
            }
        }

        $vehicle->update(['status' => $newStatus]);

        return $this->success(
            new AdminVehicleResource($vehicle->fresh()->load(['seller', 'media'])),
            "Vehicle status updated to '{$newStatus}'.",
        );
    }

    /**
     * GET /api/v1/admin/vehicles/export
     * Stream an XLSX export of the filtered vehicle inventory.
     *
     * Accepts the same filters as index (status, search).
     */
    public function export(Request $request): StreamedResponse
    {
        $query = Vehicle::with('seller')
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->search, fn ($q, $term) => $q->search($term))
            ->orderByDesc('created_at');

        $filename = 'vehicles-export-' . now()->format('Y-m-d') . '.xlsx';

        return (new VehicleExport())->download($query, $filename);
    }
}
