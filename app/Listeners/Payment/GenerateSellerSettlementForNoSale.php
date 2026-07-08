<?php

namespace App\Listeners\Payment;

use App\Events\Auction\LotDidNotSell;
use App\Services\Payment\SellerSettlementService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Applies the seller no-sale fee settlement when a lot fails to sell.
 */
class GenerateSellerSettlementForNoSale implements ShouldQueue, ShouldBeUnique
{
    public int $tries = 3;

    public function __construct(
        private readonly SellerSettlementService $settlements,
    ) {}

    public function uniqueId(): string
    {
        return 'seller_settlement_no_sale';
    }

    public function uniqueViaId(LotDidNotSell $event): string
    {
        return (string) $event->lot->id;
    }

    public function handle(LotDidNotSell $event): void
    {
        try {
            $this->settlements->finalizeNoSale($event->lot);
        } catch (UniqueConstraintViolationException) {
            // Settlement already exists — safe to discard.
        }
    }

    public function failed(LotDidNotSell $event, \Throwable $exception): void
    {
        if ($exception instanceof UniqueConstraintViolationException) {
            return;
        }

        \Illuminate\Support\Facades\Log::error('Failed to generate seller no-sale settlement', [
            'lot_id'    => $event->lot->id,
            'exception' => $exception->getMessage(),
        ]);
    }
}
