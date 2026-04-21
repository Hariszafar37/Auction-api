<?php

namespace App\Services\Pickup;

use App\Enums\PickupStatus;
use App\Mail\PickupReadyNotification;
use App\Mail\TransportQuoteReceived;
use App\Models\AuctionLot;
use App\Models\Invoice;
use App\Models\PurchaseDetail;
use App\Models\TransportRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class PurchaseService
{
    /**
     * Create a PurchaseDetail for a won lot. Idempotent.
     */
    public function createForLot(AuctionLot $lot): PurchaseDetail
    {
        return PurchaseDetail::firstOrCreate(
            ['lot_id' => $lot->id],
            [
                'buyer_id'      => $lot->buyer_id,
                'pickup_status' => PickupStatus::AwaitingPayment,
            ]
        );
    }

    /**
     * Called when an invoice is fully paid. Transitions pickup status from
     * awaiting_payment → ready_for_pickup and queues the PickupReady email.
     */
    public function handleInvoicePaid(Invoice $invoice): void
    {
        if (! $invoice->isPaid()) {
            return;
        }

        $purchase = PurchaseDetail::where('lot_id', $invoice->lot_id)->first();

        if (! $purchase || $purchase->pickup_status !== PickupStatus::AwaitingPayment) {
            return;
        }

        $purchase->update(['pickup_status' => PickupStatus::ReadyForPickup]);

        // Invalidate any cached gate pass PDF — payment status changed.
        Cache::forget("gate_pass_lot_{$invoice->lot_id}");

        $purchase->loadMissing('buyer');
        if ($purchase->buyer?->email) {
            Mail::to($purchase->buyer->email)->queue(new PickupReadyNotification($purchase));
        }
    }

    /**
     * Transition pickup status following the state machine.
     * Throws \InvalidArgumentException on invalid transitions.
     */
    public function transitionPickupStatus(PurchaseDetail $purchase, PickupStatus $newStatus): PurchaseDetail
    {
        if (! $purchase->pickup_status->canTransitionTo($newStatus)) {
            throw new \InvalidArgumentException(
                "Cannot transition pickup status from {$purchase->pickup_status->value} to {$newStatus->value}."
            );
        }

        $updates = ['pickup_status' => $newStatus];

        if ($newStatus === PickupStatus::PickedUp) {
            $updates['picked_up_at'] = now();
        }

        $purchase->update($updates);

        return $purchase->fresh();
    }

    /**
     * Update document milestones for a lot.
     */
    public function updateDocuments(PurchaseDetail $purchase, array $data): PurchaseDetail
    {
        $updates = [];

        if (array_key_exists('title_received', $data)) {
            $updates['title_received_at'] = $data['title_received'] ? ($purchase->title_received_at ?? now()) : null;
        }

        if (array_key_exists('title_verified', $data)) {
            $updates['title_verified_at'] = $data['title_verified'] ? ($purchase->title_verified_at ?? now()) : null;
        }

        if (array_key_exists('title_released', $data)) {
            $updates['title_released_at'] = $data['title_released'] ? ($purchase->title_released_at ?? now()) : null;
        }

        $purchase->update($updates);

        return $purchase->fresh();
    }

    /**
     * Ensure the purchase has a gate pass token. Generates lazily on first call.
     * Returns the token string.
     */
    public function ensureGatePassToken(PurchaseDetail $purchase): string
    {
        if (! $purchase->gate_pass_token) {
            $purchase->update([
                'gate_pass_token'        => Str::uuid()->toString(),
                'gate_pass_generated_at' => now(),
            ]);
            $purchase->refresh();
        }

        return $purchase->gate_pass_token;
    }

    /**
     * Add or update pickup notes on a purchase detail.
     */
    public function addNote(PurchaseDetail $purchase, string $notes): PurchaseDetail
    {
        $purchase->update(['pickup_notes' => $notes]);
        return $purchase->fresh();
    }

    /**
     * Queue a TransportQuoteReceived notification when admin adds a quote.
     */
    public function notifyTransportQuote(TransportRequest $request): void
    {
        $request->loadMissing(['buyer', 'lot.vehicle']);
        if ($request->buyer?->email) {
            Mail::to($request->buyer->email)->queue(new TransportQuoteReceived($request));
        }
    }
}
