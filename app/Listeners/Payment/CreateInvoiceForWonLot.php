<?php

namespace App\Listeners\Payment;

use App\Events\Auction\UserWonLot;
use App\Services\Payment\InvoiceService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;

class CreateInvoiceForWonLot implements ShouldQueue, ShouldBeUnique
{
    public int $tries = 3;

    public function __construct(
        private readonly InvoiceService $invoiceService,
    ) {}

    // Unique key prevents duplicate jobs for the same lot
    public function uniqueId(): string
    {
        return 'invoice_for_lot';
    }

    public function handle(UserWonLot $event): void
    {
        try {
            $this->invoiceService->createForLot($event->lot);
        } catch (UniqueConstraintViolationException) {
            // Invoice already exists — safe to discard
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

        \Illuminate\Support\Facades\Log::error('Failed to create invoice for won lot', [
            'lot_id'    => $event->lot->id,
            'buyer_id'  => $event->lot->buyer_id,
            'exception' => $exception->getMessage(),
        ]);
    }
}
