<?php

use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\User;
use App\Models\Vehicle;
use App\Enums\AuctionStatus;
use App\Enums\LotStatus;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

/**
 * Build a lot in countdown whose timer ended `$secondsAgo` ago — i.e. the
 * auctioneer has not yet closed it, but bidding should be frozen.
 */
function makeFinalizingLot(int $secondsAgo = 5): array
{
    $seller  = User::factory()->create(['status' => 'active']);
    $creator = User::factory()->create(['status' => 'active']);

    $auction = Auction::create([
        'title'      => 'Finalizing Test Auction',
        'location'   => 'Baltimore, MD',
        'starts_at'  => now()->subHour(),
        'status'     => AuctionStatus::Live,
        'created_by' => $creator->id,
    ]);

    $vehicle = Vehicle::factory()->create([
        'seller_id' => $seller->id,
        'status'    => 'in_auction',
    ]);

    $lot = AuctionLot::create([
        'auction_id'               => $auction->id,
        'vehicle_id'               => $vehicle->id,
        'lot_number'               => 1,
        'status'                   => LotStatus::Countdown,
        'starting_bid'             => 1000,
        'dealer_only'              => false,
        'countdown_seconds'        => 30,
        'countdown_ends_at'        => now()->subSeconds($secondsAgo),
        'requires_seller_approval' => false,
    ]);

    return [$auction, $lot];
}

it('blocks a manual bid once the countdown timer has elapsed', function () {
    [$auction, $lot] = makeFinalizingLot();

    $buyer = User::factory()->create(['status' => 'active']);
    $this->givePaymentMethod($buyer);

    $response = $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/auctions/{$auction->id}/lots/{$lot->id}/bids", [
            'amount' => 5000,
        ]);

    $response->assertStatus(422);
    expect($response->json('errors.lot.0'))->toContain('finalized');
});

it('blocks a proxy bid once the countdown timer has elapsed', function () {
    [$auction, $lot] = makeFinalizingLot();

    $buyer = User::factory()->create(['status' => 'active']);
    $this->givePaymentMethod($buyer);

    $response = $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/auctions/{$auction->id}/lots/{$lot->id}/proxy-bid", [
            'max_amount' => 5000,
        ]);

    $response->assertStatus(422);
    expect($response->json('errors.lot.0'))->toContain('finalized');
});

it('still accepts a bid while the countdown is genuinely live', function () {
    // Timer ends in the future → bidding remains open.
    $seller  = User::factory()->create(['status' => 'active']);
    $creator = User::factory()->create(['status' => 'active']);

    $auction = Auction::create([
        'title'      => 'Live Countdown Auction',
        'location'   => 'Baltimore, MD',
        'starts_at'  => now()->subHour(),
        'status'     => AuctionStatus::Live,
        'created_by' => $creator->id,
    ]);

    $vehicle = Vehicle::factory()->create(['seller_id' => $seller->id, 'status' => 'in_auction']);

    $lot = AuctionLot::create([
        'auction_id'               => $auction->id,
        'vehicle_id'               => $vehicle->id,
        'lot_number'               => 1,
        'status'                   => LotStatus::Countdown,
        'starting_bid'             => 1000,
        'dealer_only'              => false,
        'countdown_seconds'        => 30,
        'countdown_ends_at'        => now()->addSeconds(20),
        'requires_seller_approval' => false,
    ]);

    $buyer = User::factory()->create(['status' => 'active']);
    $this->givePaymentMethod($buyer);

    $response = $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/auctions/{$auction->id}/lots/{$lot->id}/bids", [
            'amount' => 5000,
        ]);

    $response->assertSuccessful();
});
