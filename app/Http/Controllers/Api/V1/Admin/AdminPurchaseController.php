<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\InvoiceStatus;
use App\Enums\PickupStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Purchase\PurchaseDetailResource;
use App\Models\AuctionLot;
use App\Models\PurchaseDetail;
use App\Services\Pickup\PurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AdminPurchaseController extends Controller
{
    public function __construct(
        private readonly PurchaseService $purchaseService,
    ) {}

    /**
     * GET /admin/purchases
     * List all won lots with pickup and payment status. Filterable.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PurchaseDetail::with(['lot.vehicle', 'lot.auction', 'invoice', 'buyer'])
            ->orderByDesc('created_at');

        if ($status = $request->query('pickup_status')) {
            $query->where('pickup_status', $status);
        }

        if ($auctionId = $request->query('auction_id')) {
            $query->whereHas('lot', fn ($q) => $q->where('auction_id', $auctionId));
        }

        if ($location = $request->query('location')) {
            $query->whereHas('lot.auction', fn ($q) => $q->where('location', 'like', "%{$location}%"));
        }

        if ($paymentStatus = $request->query('payment_status')) {
            $query->whereHas('invoice', fn ($q) => $q->where('status', $paymentStatus));
        }

        $purchases = $query->paginate($request->integer('per_page', 20));

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
     * GET /admin/purchases/{lot_id}
     */
    public function show(int $lotId): JsonResponse
    {
        $purchase = PurchaseDetail::where('lot_id', $lotId)
            ->with(['lot.vehicle', 'lot.auction', 'invoice.payments', 'buyer', 'transportRequests.buyer'])
            ->first();

        if (! $purchase) {
            return $this->error('Purchase not found.', 404);
        }

        return $this->success(new PurchaseDetailResource($purchase));
    }

    /**
     * PATCH /admin/purchases/{lot_id}/status
     * Transition pickup status. Validates state machine rules.
     */
    public function updateStatus(Request $request, int $lotId): JsonResponse
    {
        $purchase = PurchaseDetail::where('lot_id', $lotId)->first();

        if (! $purchase) {
            return $this->error('Purchase not found.', 404);
        }

        $validated = $request->validate([
            'status' => 'required|string|in:ready_for_pickup,gate_pass_issued,picked_up',
            'notes'  => 'nullable|string|max:2000',
        ]);

        $newStatus = PickupStatus::from($validated['status']);

        try {
            $purchase = $this->purchaseService->transitionPickupStatus($purchase, $newStatus);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        if (isset($validated['notes'])) {
            $purchase = $this->purchaseService->addNote($purchase, $validated['notes']);
        }

        return $this->success(
            new PurchaseDetailResource($purchase->load(['lot.vehicle', 'lot.auction', 'invoice', 'buyer'])),
            'Pickup status updated.'
        );
    }

    /**
     * PATCH /admin/purchases/{lot_id}/documents
     * Mark document milestones (title received, verified, released).
     */
    public function updateDocuments(Request $request, int $lotId): JsonResponse
    {
        $purchase = PurchaseDetail::where('lot_id', $lotId)->first();

        if (! $purchase) {
            return $this->error('Purchase not found.', 404);
        }

        $validated = $request->validate([
            'title_received' => 'sometimes|boolean',
            'title_verified' => 'sometimes|boolean',
            'title_released' => 'sometimes|boolean',
        ]);

        $purchase = $this->purchaseService->updateDocuments($purchase, $validated);

        return $this->success(
            new PurchaseDetailResource($purchase->load(['lot.vehicle', 'lot.auction', 'invoice', 'buyer'])),
            'Document milestones updated.'
        );
    }

    /**
     * POST /admin/purchases/{lot_id}/notes
     * Set/update pickup notes visible to the buyer.
     */
    public function addNote(Request $request, int $lotId): JsonResponse
    {
        $purchase = PurchaseDetail::where('lot_id', $lotId)->first();

        if (! $purchase) {
            return $this->error('Purchase not found.', 404);
        }

        $validated = $request->validate([
            'notes' => 'required|string|max:2000',
        ]);

        $purchase = $this->purchaseService->addNote($purchase, $validated['notes']);

        return $this->success(
            new PurchaseDetailResource($purchase->load(['lot.vehicle', 'lot.auction', 'invoice', 'buyer'])),
            'Notes updated.'
        );
    }

    /**
     * POST /admin/purchases/{lot_id}/gate-pass/revoke
     * Revoke an issued gate pass. Optionally rolls status back to ready_for_pickup.
     */
    public function revokeGatePass(Request $request, int $lotId): JsonResponse
    {
        $purchase = PurchaseDetail::where('lot_id', $lotId)->first();

        if (! $purchase) {
            return $this->error('Purchase not found.', 404);
        }

        if (! $purchase->gate_pass_generated_at) {
            return $this->error('No gate pass has been generated for this lot.', 422);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $updates = [
            'gate_pass_revoked_at' => now(),
            'revocation_reason'    => $validated['reason'] ?? null,
        ];

        if ($purchase->pickup_status === PickupStatus::GatePassIssued) {
            $updates['pickup_status'] = PickupStatus::ReadyForPickup;
        }

        $purchase->update($updates);

        Cache::forget("gate_pass_lot_{$lotId}");

        return $this->success(
            new PurchaseDetailResource($purchase->fresh()->load(['lot.vehicle', 'lot.auction', 'invoice', 'buyer'])),
            'Gate pass revoked.'
        );
    }

    /**
     * POST /admin/purchases/bulk-ready
     * Mark all awaiting_payment lots in an auction as ready_for_pickup, skipping unpaid invoices.
     */
    public function bulkReady(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'auction_event_id' => 'required|integer|exists:auctions,id',
        ]);

        $skipped = [];
        $updated = 0;

        AuctionLot::where('auction_id', $validated['auction_event_id'])
            ->with(['purchaseDetail', 'invoice'])
            ->get()
            ->each(function ($lot) use (&$skipped, &$updated) {
                if (! $lot->purchaseDetail) {
                    return;
                }

                if ($lot->invoice?->status !== InvoiceStatus::Paid) {
                    if ($lot->lot_number) {
                        $skipped[] = (string) $lot->lot_number;
                    }
                    return;
                }

                if ($lot->purchaseDetail->pickup_status === PickupStatus::AwaitingPayment) {
                    try {
                        $this->purchaseService->transitionPickupStatus(
                            $lot->purchaseDetail,
                            PickupStatus::ReadyForPickup
                        );
                        $updated++;
                    } catch (\InvalidArgumentException) {
                        // Already past this status — skip silently
                    }
                }
            });

        $message = "{$updated} lots marked ready for pickup.";
        if (count($skipped)) {
            $message .= ' ' . count($skipped) . ' skipped — payment not yet complete.';
        }

        return $this->success([
            'updated'       => $updated,
            'skipped_count' => count($skipped),
            'skipped_lots'  => $skipped,
            'message'       => $message,
        ]);
    }
}
