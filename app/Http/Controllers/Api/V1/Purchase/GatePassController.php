<?php

namespace App\Http\Controllers\Api\V1\Purchase;

use App\Http\Controllers\Controller;
use App\Models\PurchaseDetail;
use App\Services\Pickup\PurchaseService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class GatePassController extends Controller
{
    public function __construct(
        private readonly PurchaseService $purchaseService,
    ) {}

    /**
     * GET /my/purchases/{lot_id}/gate-pass
     * Download the gate pass PDF. Only accessible when invoice is fully paid.
     */
    public function download(Request $request, int $lotId): Response|JsonResponse
    {
        $purchase = PurchaseDetail::where('lot_id', $lotId)
            ->with(['lot.vehicle', 'lot.auction', 'buyer', 'invoice'])
            ->first();

        if (! $purchase) {
            return $this->error('Purchase not found.', 404);
        }

        $this->authorize('downloadGatePass', $purchase);


        // Ensure a stable token exists (lazy generation).
        $this->purchaseService->ensureGatePassToken($purchase);
        $purchase->refresh();

        $pdfContent = Cache::remember("gate_pass_lot_{$lotId}", 600, function () use ($purchase) {
            return Pdf::loadView('purchases.gate-pass', ['purchase' => $purchase])->output();
        });

        return response($pdfContent, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"gate-pass-lot-{$purchase->lot->lot_number}.pdf\"",
        ]);
    }

    /**
     * GET /verify/gate-pass/{token}  — public, no auth required.
     * Used by yard staff scanning the QR code on a gate pass.
     */
    public function verify(string $token): JsonResponse
    {
        $purchase = PurchaseDetail::where('gate_pass_token', $token)
            ->with(['lot.vehicle', 'buyer', 'invoice'])
            ->first();

        if (! $purchase || ! $purchase->invoice?->isPaid()) {
            return response()->json(['valid' => false], 200);
        }

        if ($purchase->gate_pass_revoked_at !== null) {
            return response()->json([
                'valid'   => false,
                'reason'  => 'revoked',
                'message' => 'This gate pass has been revoked. Please contact the auction house.',
            ]);
        }

        $vehicle = $purchase->lot?->vehicle;

        return response()->json([
            'valid'   => true,
            'vehicle' => $vehicle
                ? "{$vehicle->year} {$vehicle->make} {$vehicle->model} — VIN {$vehicle->vin}"
                : null,
            'buyer'   => $purchase->buyer?->name,
            'paid_at' => $purchase->invoice->paid_at?->toIso8601String(),
        ]);
    }
}
