<?php

namespace App\Services\Payment;

use App\Enums\InvoiceStatus;
use App\Models\AuctionLot;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function __construct(
        private readonly FeeCalculationService $fees,
    ) {}

    /**
     * Create an invoice for a won auction lot.
     * Idempotent — returns existing invoice if already created for this lot.
     */
    public function createForLot(AuctionLot $lot): Invoice
    {
        // Idempotency guard
        $existing = Invoice::where('lot_id', $lot->id)->first();
        if ($existing) {
            return $existing;
        }

        $lot->loadMissing(['auction', 'vehicle']);

        $salePrice = $lot->sold_price;
        $location  = $lot->auction?->location;

        $breakdown = $this->fees->calculate($salePrice, $location);

        return DB::transaction(function () use ($lot, $salePrice, $breakdown) {
            return Invoice::create([
                'invoice_number'     => $this->generateInvoiceNumber(),
                'lot_id'             => $lot->id,
                'auction_id'         => $lot->auction_id,
                'buyer_id'           => $lot->buyer_id,
                'vehicle_id'         => $lot->vehicle_id,
                'sale_price'         => $salePrice,
                'deposit_amount'     => $breakdown['deposit_amount'],
                'buyer_fee_amount'   => $breakdown['buyer_fee_amount'],
                'tax_amount'         => $breakdown['tax_amount'],
                'tags_amount'        => $breakdown['tags_amount'],
                'storage_days'       => 0,
                'storage_fee_amount' => 0,
                'total_amount'       => $breakdown['total_amount'],
                'amount_paid'        => 0,
                'balance_due'        => $breakdown['total_amount'],
                'status'             => InvoiceStatus::Pending,
                'fee_snapshot'       => $breakdown['snapshot'],
                'due_at'             => now()->addDays(7),
            ]);
        });
    }

    /**
     * Recalculate storage fees and update the invoice.
     * Called when admin updates storage days after initial invoice creation.
     */
    public function updateStorage(Invoice $invoice, int $storageDays): Invoice
    {
        $lot      = $invoice->lot()->with('auction')->first();
        $location = $lot?->auction?->location;

        $breakdown = $this->fees->calculate(
            $invoice->sale_price,
            $location,
            $storageDays
        );

        $invoice->update([
            'storage_days'       => $storageDays,
            'storage_fee_amount' => $breakdown['storage_fee_amount'],
            'total_amount'       => $breakdown['total_amount'],
            'balance_due'        => max(0, $breakdown['total_amount'] - (float) $invoice->amount_paid),
            'fee_snapshot'       => $breakdown['snapshot'],
        ]);

        // Re-evaluate status after update
        $invoice->recalculateBalance();

        return $invoice->fresh();
    }

    private function generateInvoiceNumber(): string
    {
        $year    = now()->year;
        $lastInv = Invoice::whereYear('created_at', $year)->lockForUpdate()->count();
        $seq     = str_pad($lastInv + 1, 5, '0', STR_PAD_LEFT);
        return "INV-{$year}-{$seq}";
    }
}
