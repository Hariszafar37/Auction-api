<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Http\Controllers\Controller;
use App\Http\Resources\Payment\SellerSettlementResource;
use App\Models\SellerSettlement;
use App\Services\Payment\SellerSettlementService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Seller-facing read-only view of settlements (earnings). Mirrors the buyer
 * InvoiceController. Only finalized settlements (sold / no-sale) are exposed —
 * seeded-but-not-closed and voided rows stay hidden.
 */
class SellerSettlementController extends Controller
{
    public function __construct(
        private readonly SellerSettlementService $settlements,
    ) {}

    /**
     * GET /my/settlements
     */
    public function index(Request $request): JsonResponse
    {
        $settlements = SellerSettlement::forSeller($request->user()->id)
            ->whereNotNull('outcome')
            ->with(['lot', 'auction', 'vehicle'])
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return $this->success(
            SellerSettlementResource::collection($settlements),
            meta: [
                'current_page' => $settlements->currentPage(),
                'last_page'    => $settlements->lastPage(),
                'per_page'     => $settlements->perPage(),
                'total'        => $settlements->total(),
            ]
        );
    }

    /**
     * GET /my/settlements/summary — seller's own aggregate earnings KPIs.
     */
    public function summary(Request $request): JsonResponse
    {
        $query = SellerSettlement::forSeller($request->user()->id);

        return $this->success($this->settlements->summarize($query));
    }

    /**
     * GET /my/settlements/{settlement}
     */
    public function show(Request $request, SellerSettlement $settlement): JsonResponse
    {
        if ($settlement->seller_id !== $request->user()->id || $settlement->outcome === null) {
            return $this->error('Settlement not found.', 404);
        }

        $settlement->load(['lot', 'auction', 'vehicle', 'adjustments']);

        return $this->success(new SellerSettlementResource($settlement));
    }

    /**
     * GET /my/settlements/{settlement}/pdf — the seller's own statement.
     */
    public function pdf(Request $request, SellerSettlement $settlement): Response
    {
        if ($settlement->seller_id !== $request->user()->id && ! $request->user()->hasRole('admin')) {
            abort(403);
        }
        if ($settlement->outcome === null) {
            abort(404);
        }

        $settlement->load(['lot', 'auction', 'vehicle', 'seller', 'adjustments']);

        $pdf = Pdf::loadView('settlements.pdf', ['settlement' => $settlement]);

        return response($pdf->output(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"settlement-{$settlement->settlement_number}.pdf\"",
        ]);
    }
}
