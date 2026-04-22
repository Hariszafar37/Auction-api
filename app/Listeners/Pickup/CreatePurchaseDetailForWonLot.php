<?php

namespace App\Listeners\Pickup;

use App\Events\Auction\UserWonLot;
use App\Services\Pickup\PurchaseService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;

class CreatePurchaseDetailForWonLot implements ShouldQueue, ShouldBeUnique
{
    public int $tries = 3;

    public function __construct(
        private readonly PurchaseService $purchaseService,
    ) {}

    public function uniqueId(): string
    {
        return 'purchase_detail_for_lot';
    }

    public function handle(UserWonLot $event): void
    {
        try {
            $this->purchaseService->createForLot($event->lot);
        } catch (UniqueConstraintViolationException) {
            // PurchaseDetail already exists — safe to discard.
        }
    }

    public function uniqueViaId(UserWonLot $event): string
    {
        return (string) $event->lot->id;
    }

    public function failed(UserWonLot $event, \Throwable $exception): void
    {
        if ($exception instanceof UniqueConstraintViolationException) {
            return;
        }

        \Illuminate\Support\Facades\Log::error('Failed to create purchase detail for won lot', [
            'lot_id'    => $event->lot->id,
            'buyer_id'  => $event->lot->buyer_id,
            'exception' => $exception->getMessage(),
        ]);
    }
}
