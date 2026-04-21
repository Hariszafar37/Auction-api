<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\TransportRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Purchase\TransportRequestResource;
use App\Models\TransportRequest;
use App\Services\Pickup\PurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminTransportController extends Controller
{
    public function __construct(
        private readonly PurchaseService $purchaseService,
    ) {}

    /**
     * GET /admin/transport-requests
     */
    public function index(Request $request): JsonResponse
    {
        $query = TransportRequest::with(['buyer', 'lot.vehicle', 'lot.auction'])
            ->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $requests = $query->paginate($request->integer('per_page', 20));

        return $this->success(
            TransportRequestResource::collection($requests),
            meta: [
                'current_page' => $requests->currentPage(),
                'last_page'    => $requests->lastPage(),
                'per_page'     => $requests->perPage(),
                'total'        => $requests->total(),
            ]
        );
    }

    /**
     * PATCH /admin/transport-requests/{id}
     * Update status, quote amount, and admin notes.
     */
    public function update(Request $request, TransportRequest $transportRequest): JsonResponse
    {
        $validated = $request->validate([
            'status'       => 'sometimes|string|in:pending,quoted,arranged,cancelled',
            'quote_amount' => 'sometimes|nullable|numeric|min:0',
            'admin_notes'  => 'sometimes|nullable|string|max:2000',
        ]);

        $newStatus   = isset($validated['status']) ? TransportRequestStatus::from($validated['status']) : null;
        $hadNoQuote  = $transportRequest->quote_amount === null;
        $addingQuote = array_key_exists('quote_amount', $validated) && $validated['quote_amount'] !== null;

        $updates = [];

        if ($newStatus !== null) {
            $updates['status'] = $newStatus->value;
        }
        if (array_key_exists('quote_amount', $validated)) {
            $updates['quote_amount'] = $validated['quote_amount'];
        }
        if (array_key_exists('admin_notes', $validated)) {
            $updates['admin_notes'] = $validated['admin_notes'];
        }

        if ($newStatus === TransportRequestStatus::Quoted && ! $transportRequest->quoted_at) {
            $updates['quoted_at'] = now();
        }
        if ($newStatus === TransportRequestStatus::Arranged && ! $transportRequest->arranged_at) {
            $updates['arranged_at'] = now();
        }
        if ($newStatus === TransportRequestStatus::Cancelled && ! $transportRequest->cancelled_at) {
            $updates['cancelled_at'] = now();
        }

        if (! empty($updates)) {
            $transportRequest->update($updates);
        }

        // Notify buyer when a quote is added for the first time
        if ($hadNoQuote && $addingQuote) {
            $this->purchaseService->notifyTransportQuote($transportRequest->fresh());
        }

        return $this->success(
            new TransportRequestResource($transportRequest->fresh()->load(['buyer', 'lot.vehicle'])),
            'Transport request updated.'
        );
    }
}
