<?php

namespace App\Http\Controllers\Api\V1\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Resources\Purchase\PurchaseDetailResource;
use App\Models\PurchaseDetail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    /**
     * GET /my/purchases
     * Buyer's list of all won lots with invoice and pickup status.
     */
    public function index(Request $request): JsonResponse
    {
        $purchases = PurchaseDetail::forBuyer($request->user()->id)
            ->with([
                'lot.vehicle',
                'lot.auction',
                'invoice',
            ])
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return $this->success(
            PurchaseDetailResource::collection($purchases),
            meta: [
                'current_page' => $purchases->currentPage(),
                'last_page'    => $purchases->lastPage(),
                'per_page'     => $purchases->perPage(),
                'total'        => $purchases->total(),
            ]
        );
    }

    /**
     * GET /my/purchases/{lot_id}
     * Full detail for a single won lot.
     */
    public function show(Request $request, int $lotId): JsonResponse
    {
        $purchase = PurchaseDetail::forBuyer($request->user()->id)
            ->where('lot_id', $lotId)
            ->with([
                'lot.vehicle',
                'lot.auction',
                'invoice.payments',
                'transportRequests',
            ])
            ->first();

        if (! $purchase) {
            return $this->error('Purchase not found.', 404);
        }

        return $this->success(new PurchaseDetailResource($purchase));
    }
}
