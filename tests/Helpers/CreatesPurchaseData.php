<?php

namespace Tests\Helpers;

use App\Enums\AuctionStatus;
use App\Enums\InvoiceStatus;
use App\Enums\LotStatus;
use App\Enums\PickupStatus;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\Invoice;
use App\Models\PurchaseDetail;
use App\Models\User;
use App\Models\Vehicle;

/**
 * Shared factory helpers for Purchase & Pickup module Feature tests.
 */
trait CreatesPurchaseData
{
    /**
     * Build a fully valid won lot with an attached invoice and purchase detail.
     * Returns the PurchaseDetail. Access $purchase->lot, $purchase->invoice, etc.
     */
    protected function makePurchase(User $buyer, array $invoiceOverrides = [], array $purchaseOverrides = []): PurchaseDetail
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
            'lot_number'               => fake()->unique()->numberBetween(1, 999),
            'status'                   => LotStatus::Countdown,
            'starting_bid'             => 1000,
            'current_bid'              => 5000,
            'sold_price'               => 5000,
            'buyer_id'                 => $buyer->id,
            'current_winner_id'        => $buyer->id,
            'requires_seller_approval' => false,
        ]);

        $invoice = Invoice::create(array_merge([
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
        ], $invoiceOverrides));

        $purchase = PurchaseDetail::create(array_merge([
            'lot_id'        => $lot->id,
            'buyer_id'      => $buyer->id,
            'pickup_status' => PickupStatus::AwaitingPayment,
        ], $purchaseOverrides));

        return $purchase->load(['lot.vehicle', 'lot.auction', 'invoice']);
    }

    /** Create and return an admin user. */
    protected function makeAdmin(): User
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');
        return $admin;
    }
}
