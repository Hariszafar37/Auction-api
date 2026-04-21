<?php

use App\Enums\InvoiceStatus;
use App\Enums\LotStatus;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolePermissionSeeder;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

// ─── Regression guard: buyer.name not buyer.full_name ────────────────────────

test('admin invoice detail returns buyer.name not buyer.full_name', function () {
    $buyer = User::factory()->create(['name' => 'John Doe', 'status' => 'active']);

    $creator = User::factory()->create(['status' => 'active']);
    $auction = Auction::create([
        'title'      => 'Regression Test Auction',
        'location'   => 'Baltimore, MD',
        'starts_at'  => now()->subHour(),
        'status'     => 'live',
        'created_by' => $creator->id,
    ]);
    $vehicle = Vehicle::create([
        'seller_id' => $creator->id,
        'vin'       => strtoupper(fake()->unique()->lexify('?????????????????')),
        'year'      => 2022,
        'make'      => 'Honda',
        'model'     => 'Accord',
        'status'    => 'in_auction',
    ]);
    $lot = AuctionLot::create([
        'auction_id'               => $auction->id,
        'vehicle_id'               => $vehicle->id,
        'lot_number'               => 1,
        'status'                   => LotStatus::Countdown,
        'starting_bid'             => 500,
        'current_bid'              => 5000,
        'sold_price'               => 5000,
        'buyer_id'                 => $buyer->id,
        'current_winner_id'        => $buyer->id,
        'requires_seller_approval' => false,
    ]);

    $invoice = Invoice::create([
        'invoice_number'  => 'INV-REGRESS-001',
        'lot_id'          => $lot->id,
        'auction_id'      => $auction->id,
        'buyer_id'        => $buyer->id,
        'vehicle_id'      => $vehicle->id,
        'sale_price'      => 5000,
        'deposit_amount'  => 300,
        'buyer_fee_amount' => 400,
        'tax_amount'      => 0,
        'tags_amount'     => 0,
        'storage_days'    => 0,
        'storage_fee_amount' => 0,
        'total_amount'    => 5400,
        'amount_paid'     => 0,
        'balance_due'     => 5400,
        'status'          => InvoiceStatus::Pending,
        'due_at'          => now()->addDays(7),
    ]);

    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    $this->actingAs($admin, 'sanctum')
         ->getJson("/api/v1/admin/invoices/{$invoice->id}")
         ->assertOk()
         ->assertJsonPath('data.buyer.name', 'John Doe')
         ->assertJsonMissingPath('data.buyer.full_name');
});

// ─── Partial payment support ─────────────────────────────────────────────────

test('invoice resource exposes remaining_balance field', function () {
    $buyer = User::factory()->create(['status' => 'active']);
    $creator = User::factory()->create(['status' => 'active']);

    $auction = Auction::create([
        'title'      => 'Balance Test Auction',
        'location'   => 'Test City',
        'starts_at'  => now()->subHour(),
        'status'     => 'live',
        'created_by' => $creator->id,
    ]);
    $vehicle = Vehicle::create([
        'seller_id' => $creator->id,
        'vin'       => strtoupper(fake()->unique()->lexify('?????????????????')),
        'year'      => 2021,
        'make'      => 'Ford',
        'model'     => 'F-150',
        'status'    => 'in_auction',
    ]);
    $lot = AuctionLot::create([
        'auction_id'               => $auction->id,
        'vehicle_id'               => $vehicle->id,
        'lot_number'               => 2,
        'status'                   => LotStatus::Countdown,
        'starting_bid'             => 1000,
        'current_bid'              => 3000,
        'sold_price'               => 3000,
        'buyer_id'                 => $buyer->id,
        'current_winner_id'        => $buyer->id,
        'requires_seller_approval' => false,
    ]);

    $invoice = Invoice::create([
        'invoice_number'     => 'INV-BALANCE-001',
        'lot_id'             => $lot->id,
        'auction_id'         => $auction->id,
        'buyer_id'           => $buyer->id,
        'vehicle_id'         => $vehicle->id,
        'sale_price'         => 3000,
        'deposit_amount'     => 300,
        'buyer_fee_amount'   => 250,
        'tax_amount'         => 0,
        'tags_amount'        => 0,
        'storage_days'       => 0,
        'storage_fee_amount' => 0,
        'total_amount'       => 3250,
        'amount_paid'        => 500,   // partial payment already made
        'balance_due'        => 2750,
        'status'             => InvoiceStatus::Partial,
        'due_at'             => now()->addDays(7),
    ]);

    // remaining_balance = total_amount - amount_paid = 3250 - 500 = 2750
    // Note: whole-number floats serialize as integers in JSON, so comparisons use int
    $this->actingAs($buyer, 'sanctum')
         ->getJson("/api/v1/my/invoices/{$invoice->id}")
         ->assertOk()
         ->assertJsonPath('data.remaining_balance', 2750)
         ->assertJsonPath('data.amount_paid', 500)
         ->assertJsonPath('data.balance_due', 2750);
});
