<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\LocationResource;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminLocationController extends Controller
{
    /**
     * GET /api/v1/admin/locations
     * List all platform locations (active + inactive).
     */
    public function index(): JsonResponse
    {
        $locations = Location::orderBy('name')->get();

        return $this->success(LocationResource::collection($locations));
    }

    /**
     * POST /api/v1/admin/locations
     * Create a new auction location.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:100'],
            'code'         => ['required', 'string', 'max:30', 'unique:locations,code'],
            'address'      => ['nullable', 'string', 'max:200'],
            'city'         => ['required', 'string', 'max:100'],
            'state'        => ['required', 'string', 'max:10'],
            'zip_code'     => ['nullable', 'string', 'max:20'],
            'phone'        => ['nullable', 'string', 'max:30'],
            'manager_name' => ['nullable', 'string', 'max:100'],
            'is_active'    => ['boolean'],
        ]);

        $location = Location::create($data);

        return $this->success(new LocationResource($location), 'Location created.', 201);
    }

    /**
     * GET /api/v1/admin/locations/{location}
     */
    public function show(Location $location): JsonResponse
    {
        return $this->success(new LocationResource($location));
    }

    /**
     * PATCH /api/v1/admin/locations/{location}
     */
    public function update(Request $request, Location $location): JsonResponse
    {
        $data = $request->validate([
            'name'         => ['sometimes', 'string', 'max:100'],
            'code'         => ['sometimes', 'string', 'max:30', "unique:locations,code,{$location->id}"],
            'address'      => ['nullable', 'string', 'max:200'],
            'city'         => ['sometimes', 'string', 'max:100'],
            'state'        => ['sometimes', 'string', 'max:10'],
            'zip_code'     => ['nullable', 'string', 'max:20'],
            'phone'        => ['nullable', 'string', 'max:30'],
            'manager_name' => ['nullable', 'string', 'max:100'],
            'is_active'    => ['boolean'],
        ]);

        $location->update($data);

        return $this->success(new LocationResource($location->fresh()), 'Location updated.');
    }

    /**
     * DELETE /api/v1/admin/locations/{location}
     * Deactivates the location rather than hard-deleting, since existing auctions
     * reference location as a string field and deletion would leave orphan data.
     */
    public function destroy(Location $location): JsonResponse
    {
        $location->update(['is_active' => false]);

        return $this->success(null, 'Location deactivated.');
    }
}
