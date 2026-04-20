<?php

namespace App\Listeners\Payment;

use App\Events\Auction\UserWonLot;
use App\Services\Payment\InvoiceService;
use Illuminate\Contracts\Queue\ShouldQueue;

class CreateInvoiceForWonLot implements ShouldQueue
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
    ) {}

    public function handle(UserWonLot $event): void
    {
        $this->invoiceService->createForLot($event->lot);
    }

    public function failed(UserWonLot $event, \Throwable $exception): void
    {
        \Illuminate\Support\Facades\Log::error('Failed to create invoice for won lot', [
            'lot_id'    => $event->lot->id,
            'buyer_id'  => $event->lot->buyer_id,
            'exception' => $exception->getMessage(),
        ]);
    }
}
