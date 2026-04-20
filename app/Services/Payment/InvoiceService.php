<?php

namespace App\Services\Payment;

use App\Enums\InvoiceStatus;
use App\Mail\InvoiceCreated;
use App\Models\AuctionLot;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Stripe\StripeClient;

class InvoiceService
{
    public function __construct(
        private readonly FeeCalculationService $fees,
    ) {}

    /**
     * Create an invoice for a won auction lot.
     * Idempotent — returns existing invoice if already created for this lot.
     * Also initiates Stripe deposit hold and queues the InvoiceCreated notification.
     */
    public function createForLot(AuctionLot $lot): Invoice
    {
        // Idempotency guard
        $existing = Invoice::where('lot_id', $lot->id)->first();
        if ($existing) {
            return $existing;
        }

        $lot->loadMissing(['auction', 'vehicle', 'buyer']);

        $salePrice = $lot->sold_price;
        $location  = $lot->auction?->location;

        $breakdown = $this->fees->calculate($salePrice, $location);

        $invoice = DB::transaction(function () use ($lot, $salePrice, $breakdown) {
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

        // FIX 5: Auto-initiate deposit hold via Stripe if configured
        if ($breakdown['deposit_amount'] > 0 && config('services.stripe.secret')) {
            $this->initiateDepositHold($invoice, $lot, (float) $breakdown['deposit_amount']);
        }

        // FIX 7: Queue invoice created notification email
        $invoice->loadMissing(['buyer', 'vehicle', 'lot']);
        if ($invoice->buyer?->email) {
            Mail::to($invoice->buyer->email)->queue(new InvoiceCreated($invoice));
        }

        return $invoice;
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

    // ─── Private helpers ─────────────────────────────────────────────────────────

    /**
     * Create a Stripe PaymentIntent with capture_method=manual to hold the deposit.
     * Failure is non-fatal — logs a warning and sets deposit_status=pending_manual.
     */
    private function initiateDepositHold(Invoice $invoice, AuctionLot $lot, float $depositAmount): void
    {
        try {
            $stripe = new StripeClient(config('services.stripe.secret'));
            $buyer  = $lot->buyer ?? $invoice->buyer;

            // Ensure Stripe customer exists
            if (! $buyer->stripe_customer_id) {
                $customer = $stripe->customers->create([
                    'email' => $buyer->email,
                    'name'  => $buyer->name,
                    'metadata' => ['user_id' => $buyer->id],
                ]);
                $buyer->update(['stripe_customer_id' => $customer->id]);
            }

            $pi = $stripe->paymentIntents->create([
                'amount'         => (int) round($depositAmount * 100),
                'currency'       => 'usd',
                'customer'       => $buyer->stripe_customer_id,
                'capture_method' => 'manual',
                'metadata'       => [
                    'invoice_id'     => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'buyer_id'       => $buyer->id,
                    'type'           => 'deposit',
                ],
                'description' => "Deposit hold — Invoice {$invoice->invoice_number}",
            ]);

            InvoicePayment::create([
                'invoice_id'           => $invoice->id,
                'user_id'              => $buyer->id,
                'method'               => 'deposit',
                'amount'               => $depositAmount,
                'reference'            => $pi->id,
                'stripe_client_secret' => $pi->client_secret,
                'status'               => 'pending',
            ]);

            $invoice->update([
                'stripe_deposit_intent_id' => $pi->id,
                'deposit_status'           => 'pending',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to initiate deposit hold for invoice', [
                'invoice_id' => $invoice->id,
                'error'      => $e->getMessage(),
            ]);
            $invoice->update(['deposit_status' => 'pending_manual']);
        }
    }

    private function generateInvoiceNumber(): string
    {
        $year    = now()->year;
        $lastInv = Invoice::whereYear('created_at', $year)->lockForUpdate()->count();
        $seq     = str_pad($lastInv + 1, 5, '0', STR_PAD_LEFT);
        return "INV-{$year}-{$seq}";
    }
}
