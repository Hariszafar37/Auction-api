<?php

namespace App\Listeners\Payment;

use App\Events\Auction\UserWonLot;
use App\Services\Payment\SellerSettlementService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Finalizes the seller settlement (commission + net proceeds + release date)
 * when a lot is sold. Mirrors CreateInvoiceForWonLot on the buyer side.
 */
class GenerateSellerSettlementForWonLot implements ShouldQueue, ShouldBeUnique
{
    public int $tries = 3;

    public function __construct(
        private readonly SellerSettlementService $settlements,
    ) {}

    public function uniqueId(): string
    {
        return 'seller_settlement_sold';
    }

    public function uniqueViaId(UserWonLot $event): string
    {
        return (string) $event->lot->id;
    }

    public function handle(UserWonLot $event): void
    {
        try {
            $this->settlements->finalizeSold($event->lot);
        } catch (UniqueConstraintViolationException) {
            // Settlement already exists — safe to discard.
        }
    }

    public function failed(UserWonLot $event, \Throwable $exception): void
    {
        if ($exception instanceof UniqueConstraintViolationException) {
            return;
        }

        \Illuminate\Support\Facades\Log::error('Failed to generate seller settlement for won lot', [
            'lot_id'    => $event->lot->id,
            'exception' => $exception->getMessage(),
        ]);
    }
}
