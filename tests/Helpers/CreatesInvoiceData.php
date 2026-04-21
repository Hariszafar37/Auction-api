<?php

namespace Tests\Helpers;

use App\Enums\AuctionStatus;
use App\Enums\InvoiceStatus;
use App\Enums\LotStatus;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use App\Models\Vehicle;

/**
 * Shared factory helpers for Payments & Fees module Feature tests.
 *
 * Register via: pest()->use(Tests\Helpers\CreatesInvoiceData::class)->in('Feature/Payment');
 */
trait CreatesInvoiceData
{
    /**
     * Build a minimal but fully valid Invoice with all required foreign-key dependencies.
     * Defaults to a $5,800 total (sale=$5000, buyer_fee=$350, tax=$300, tags=$150).
     */
    protected function makeInvoice(User $buyer, array $overrides = []): Invoice
    {
        $creator = User::factory()->create(['status' => 'active']);

        $auction = Auction::create([
            'title'      => 'Test Auction',
            'location'   => 'Baltimore, MD',
            'starts_at'  => now()->subHour(),
            'status'     => AuctionStatus::Live,
            'created_by' => $creator->id,
        ]);

        $vehicle = Vehicle::create([
            'seller_id' => $creator->id,
            'vin'       => strtoupper(fake()->unique()->lexify('?????????????????')),
            'year'      => 2022,
            'make'      => 'Toyota',
            'model'     => 'Camry',
            'status'    => 'in_auction',
        ]);

        $lot = AuctionLot::create([
            'auction_id'               => $auction->id,
            'vehicle_id'               => $vehicle->id,
            'lot_number'               => 1,
            'status'                   => LotStatus::Countdown,
            'starting_bid'             => 1000,
            'current_bid'              => 5000,
            'sold_price'               => 5000,
            'buyer_id'                 => $buyer->id,
            'current_winner_id'        => $buyer->id,
            'requires_seller_approval' => false,
        ]);

        return Invoice::create(array_merge([
            'invoice_number'     => 'INV-' . strtoupper(fake()->unique()->lexify('????')),
            'lot_id'             => $lot->id,
            'auction_id'         => $auction->id,
            'buyer_id'           => $buyer->id,
            'vehicle_id'         => $vehicle->id,
            'sale_price'         => 5000,
            'deposit_amount'     => 300,
            'buyer_fee_amount'   => 350,
            'tax_amount'         => 300,
            'tags_amount'        => 150,
            'storage_days'       => 0,
            'storage_fee_amount' => 0,
            'total_amount'       => 5800,
            'amount_paid'        => 0,
            'balance_due'        => 5800,
            'status'             => InvoiceStatus::Pending,
            'due_at'             => now()->addDays(7),
        ], $overrides));
    }

    /**
     * Create a completed InvoicePayment for the given invoice, incrementing amount_paid.
     */
    protected function makePayment(Invoice $invoice, float $amount, string $method = 'cash'): InvoicePayment
    {
        $payment = InvoicePayment::create([
            'invoice_id'   => $invoice->id,
            'user_id'      => $invoice->buyer_id,
            'method'       => $method,
            'amount'       => $amount,
            'status'       => 'completed',
            'processed_at' => now(),
        ]);

        $invoice->recalculateBalance();

        return $payment;
    }

    /**
     * Return an admin user with the admin role already assigned.
     */
    protected function makeAdmin(): User
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');
        return $admin;
    }
}
