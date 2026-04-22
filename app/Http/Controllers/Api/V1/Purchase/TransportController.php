<?php

namespace App\Http\Controllers\Api\V1\Purchase;

use App\Http\Controllers\Controller;
use App\Enums\TransportRequestStatus;
use App\Http\Resources\Purchase\TransportRequestResource;
use App\Models\PurchaseDetail;
use App\Models\TransportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransportController extends Controller
{
    /**
     * POST /my/purchases/{lot_id}/transport
     * Submit a transport quote request for a won lot.
     */
    public function store(Request $request, int $lotId): JsonResponse
    {
        $purchase = PurchaseDetail::where('lot_id', $lotId)->first();

        if (! $purchase) {
            return $this->error('Purchase not found.', 404);
        }

        $this->authorize('requestTransport', $purchase);

        // FIX 3: guard against duplicate active requests
        $existing = TransportRequest::where('lot_id', $lotId)
            ->whereNotIn('status', [TransportRequestStatus::Cancelled->value])
            ->exists();

        if ($existing) {
            return $this->error(
                'A transport request already exists for this vehicle. Cancel the existing request before submitting a new one.',
                422
            );
        }

        $validated = $request->validate([
            'pickup_location'  => 'required|string|max:255',
            'delivery_address' => 'required|string|max:1000',
            'preferred_dates'  => 'nullable|string|max:255',
            'notes'            => 'nullable|string|max:2000',
        ]);

        $transport = TransportRequest::create(array_merge($validated, [
            'lot_id'   => $lotId,
            'buyer_id' => $request->user()->id,
            'status'   => TransportRequestStatus::Pending,
        ]));

        return $this->success(new TransportRequestResource($transport), 'Transport request submitted.', 201);
    }
}
