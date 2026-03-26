<?php

namespace App\Http\Controllers\Api\V1\Dealer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminVehicleResource;
use App\Http\Resources\Auction\AuctionLotResource;
use App\Models\Auction;
use App\Models\Vehicle;
use App\Services\Auction\AuctionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DealerVehicleController extends Controller
{
    public function __construct(
        private readonly AuctionService $auctionService,
    ) {}

    /**
     * GET /api/v1/my/vehicles
     * Paginated list of vehicles owned by the authenticated dealer.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Vehicle::with('media')
            ->where('seller_id', $request->user()->id)
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->search, function ($q, $search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('vin',   'like', "%{$search}%")
                          ->orWhere('make',  'like', "%{$search}%")
                          ->orWhere('model', 'like', "%{$search}%");
                });
            })
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
     * POST /api/v1/my/vehicles
     * Create a vehicle for the authenticated dealer.
     * seller_id is set automatically — dealers cannot create vehicles on behalf of others.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
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

        // Ownership is always the authenticated dealer — never trust a client-supplied seller_id
        $data['seller_id'] = $request->user()->id;
        $data['status']    = 'available';

        $vehicle = Vehicle::create($data);

        return $this->success(
            new AdminVehicleResource($vehicle),
            'Vehicle added successfully.',
            201,
        );
    }

    /**
     * GET /api/v1/my/vehicles/{vehicle}
     * Show a single vehicle. Returns 404 if the vehicle is not owned by the requesting dealer.
     */
    public function show(Request $request, Vehicle $vehicle): JsonResponse
    {
        if ($vehicle->seller_id !== $request->user()->id) {
            return $this->error('Vehicle not found.', 404, 'not_found');
        }

        return $this->success(new AdminVehicleResource($vehicle->load('media')));
    }

    /**
     * POST /api/v1/my/vehicles/{vehicle}/submit-to-auction
     * Dealer submits an owned, available vehicle to a draft or scheduled auction.
     * Creates an AuctionLot via AuctionService::addLot() — ownership enforced here,
     * business-rule validation happens inside the service.
     */
    public function submitToAuction(Request $request, Vehicle $vehicle): JsonResponse
    {
        if ($vehicle->seller_id !== $request->user()->id) {
            return $this->error('Vehicle not found.', 404, 'not_found');
        }

        $data = $request->validate([
            'auction_id'    => ['required', 'integer', 'exists:auctions,id'],
            'starting_bid'  => ['required', 'integer', 'min:100'],
            'reserve_price' => ['nullable', 'integer', 'min:0'],
        ]);

        $auction = Auction::findOrFail($data['auction_id']);

        try {
            $lot = $this->auctionService->addLot($auction, $vehicle, $data);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, 'submit_failed', $e->errors());
        }

        return $this->success(
            new AuctionLotResource($lot),
            'Vehicle submitted to auction successfully.',
            201,
        );
    }
}
