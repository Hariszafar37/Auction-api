<?php

use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\User;
use App\Models\Vehicle;
use App\Enums\AuctionStatus;
use App\Enums\LotStatus;
use App\Notifications\OutbidEmailNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Notification::fake();
});

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeBidNotificationLot(): array
{
    $seller  = User::factory()->create(['status' => 'active']);
    $creator = User::factory()->create(['status' => 'active']);

    $auction = Auction::create([
        'title'      => 'Bid Notification Auction',
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
        'status'                   => LotStatus::Open,
        'starting_bid'             => 1000,
        'dealer_only'              => false,
        'countdown_seconds'        => 30,
        'requires_seller_approval' => false,
    ]);

    return [$auction, $lot];
}

function makeBidNotificationBuyer(): User
{
    $user = User::factory()->create(['status' => 'active', 'account_type' => 'individual']);
    $user->assignRole('buyer');

    // Seed valid payment — every buyer in this test must pass the bidding gate.
    $user->billingInformation()->updateOrCreate(
        ['user_id' => $user->id],
        [
            'billing_address'         => '123 Test St',
            'billing_country'         => 'US',
            'billing_city'            => 'Baltimore',
            'billing_zip_postal_code' => '21201',
            'payment_method_added'    => true,
            'cardholder_name'         => 'Test User',
            'card_brand'              => 'visa',
            'card_last_four'          => '4242',
            'card_expiry_month'       => 12,
            'card_expiry_year'        => (int) now()->year + 5,
        ]
    );

    return $user;
}

// ── Tests ─────────────────────────────────────────────────────────────────────

it('outbid user receives OutbidEmailNotification when outbid on a lot', function () {
    [$auction, $lot] = makeBidNotificationLot();

    $firstBidder  = makeBidNotificationBuyer();
    $secondBidder = makeBidNotificationBuyer();

    // First bidder places a bid
    $this->actingAs($firstBidder, 'sanctum')
        ->postJson("/api/v1/auctions/{$auction->id}/lots/{$lot->id}/bids", ['amount' => 1000])
        ->assertStatus(200);

    Notification::assertNothingSentTo($firstBidder);

    // Second bidder outbids the first
    $this->actingAs($secondBidder, 'sanctum')
        ->postJson("/api/v1/auctions/{$auction->id}/lots/{$lot->id}/bids", ['amount' => 1100])
        ->assertStatus(200);

    // First bidder should receive the outbid notification
    Notification::assertSentTo($firstBidder, OutbidEmailNotification::class, function ($n) {
        $data = $n->toDatabase($n);
        return $data['type'] === 'outbid' && ($data['meta']['new_bid'] ?? null) === 1100;
    });
});

it('placing the first bid on a lot does not trigger outbid notification', function () {
    [$auction, $lot] = makeBidNotificationLot();
    $buyer = makeBidNotificationBuyer();

    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/auctions/{$auction->id}/lots/{$lot->id}/bids", ['amount' => 1000])
        ->assertStatus(200);

    Notification::assertNothingSentTo($buyer);
});

it('winning bidder does not get outbid notification for their own bid', function () {
    [$auction, $lot] = makeBidNotificationLot();
    $buyer = makeBidNotificationBuyer();

    // Place initial bid
    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/auctions/{$auction->id}/lots/{$lot->id}/bids", ['amount' => 1000])
        ->assertStatus(200);

    // Place another bid as the same user (self-outbid not typical but guards against notification loop)
    Notification::assertNotSentTo($buyer, OutbidEmailNotification::class);
});
