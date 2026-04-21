<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Fee\StoreFeeConfigurationRequest;
use App\Http\Requests\Admin\Fee\UpdateFeeConfigurationRequest;
use App\Http\Resources\Fee\FeeConfigurationResource;
use App\Models\FeeConfiguration;
use App\Services\Payment\FeeCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminFeeController extends Controller
{
    public function __construct(
        private readonly FeeCalculationService $fees,
    ) {}

    /**
     * GET /admin/fees
     * Returns all fee configurations with their tiers.
     */
    public function index(Request $request): JsonResponse
    {
        $location = $request->query('location');

        $query = FeeConfiguration::with('tiers')->orderBy('sort_order')->orderBy('fee_type');

        if ($location !== null) {
            $query->where('location', $location ?: null);
        }

        return $this->success(FeeConfigurationResource::collection($query->get()));
    }

    /**
     * POST /admin/fees
     */
    public function store(StoreFeeConfigurationRequest $request): JsonResponse
    {
        $config = FeeConfiguration::create($request->validated());

        return $this->success(
            new FeeConfigurationResource($config->load('tiers')),
            'Fee configuration created.',
            201
        );
    }

    /**
     * GET /admin/fees/{fee}
     */
    public function show(FeeConfiguration $fee): JsonResponse
    {
        return $this->success(new FeeConfigurationResource($fee->load('tiers')));
    }

    /**
     * PATCH /admin/fees/{fee}
     * Supports full tier replacement when `tiers` array is provided.
     */
    public function update(UpdateFeeConfigurationRequest $request, FeeConfiguration $fee): JsonResponse
    {
        DB::transaction(function () use ($request, $fee) {
            $data = collect($request->validated())->except('tiers')->all();
            $fee->update($data);

            if ($request->has('tiers')) {
                $fee->tiers()->delete();
                foreach ($request->input('tiers', []) as $i => $tierData) {
                    $fee->tiers()->create([
                        'sale_price_from'      => $tierData['sale_price_from'],
                        'sale_price_to'        => $tierData['sale_price_to'] ?? null,
                        'fee_calculation_type' => $tierData['fee_calculation_type'],
                        'fee_amount'           => $tierData['fee_amount'],
                        'sort_order'           => $tierData['sort_order'] ?? $i,
                    ]);
                }
            }
        });

        return $this->success(
            new FeeConfigurationResource($fee->fresh()->load('tiers')),
            'Fee configuration updated.'
        );
    }

    /**
     * DELETE /admin/fees/{fee}
     */
    public function destroy(FeeConfiguration $fee): JsonResponse
    {
        $fee->delete();
        return $this->success(null, 'Fee configuration deleted.');
    }

    /**
     * GET /admin/fees/preview?sale_price=X&location=Y
     * Returns a calculated fee breakdown for a given sale price and location.
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'sale_price' => ['required', 'integer', 'min:1'],
            'location'   => ['nullable', 'string'],
        ]);

        $breakdown = $this->fees->preview(
            (int) $request->query('sale_price'),
            $request->query('location'),
        );

        return $this->success($breakdown, 'Fee preview calculated.');
    }
}
