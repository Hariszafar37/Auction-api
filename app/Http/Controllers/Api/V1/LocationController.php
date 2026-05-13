<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LocationResource;
use App\Models\Location;
use Illuminate\Http\JsonResponse;

class LocationController extends Controller
{
    /**
     * GET /api/v1/locations
     * List active auction locations for public use (filters, auction display, etc.).
     */
    public function index(): JsonResponse
    {
        $locations = Location::active()->orderBy('name')->get();

        return $this->success(LocationResource::collection($locations));
    }
}
