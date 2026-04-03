<?php

use App\Enums\AuctionStatus;
use App\Enums\LotStatus;
use App\Events\Account\AccountApproved;
use App\Events\Account\AccountRejected;
use App\Events\Account\DocumentStatusUpdated;
use App\Events\Account\POAApproved;
use App\Events\Account\POARejected;
use App\Events\Auction\BidPlaced;
use App\Events\Auction\OutbidNotification as OutbidBroadcastEvent;
use App\Events\Auction\UserWonLot;
use App\Listeners\Account\SendAccountApprovedNotification;
use App\Listeners\Account\SendAccountRejectedNotification;
use App\Listeners\Account\SendDocumentStatusNotification;
use App\Listeners\Account\SendPOAApprovedNotification;
use App\Listeners\Account\SendPOARejectedNotification;
use App\Listeners\Auction\SendAuctionWonNotification;
use App\Listeners\Auction\SendBidPlacedNotification;
use App\Listeners\Auction\SendOutbidEmailNotification;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\Bid;
use App\Models\PowerOfAttorney;
use App\Models\User;
use App\Models\UserDocument;
use App\Models\Vehicle;
use App\Notifications\AccountApprovedNotification;
use App\Notifications\AccountRejectedNotification;
use App\Notifications\AuctionWonNotification;
use App\Notifications\BidPlacedNotification;
use App\Notifications\DocumentStatusUpdatedNotification;
use App\Notifications\OutbidEmailNotification;
use App\Notifications\PoaApprovedNotification;
use App\Notifications\PoaRejectedNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

// ══ EVENT DISPATCH COVERAGE ═══════════════════════════════════════════════════

it('approving a dealer dispatches AccountApproved event', function () {
    Event::fake([AccountApproved::class]);
    Notification::fake();

    $admin  = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');
    $dealer = User::factory()->create(['status' => 'pending_activation', 'account_type' => 'dealer']);
    $dealer->assignRole('dealer');
    $dealer->dealerProfile()->create([
        'company_name'    => 'Test Motors',
        'dealer_license'  => 'DLR-001',
        'approval_status' => 'pending',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/dealers/{$dealer->id}/approve")
        ->assertStatus(200);

    Event::assertDispatched(AccountApproved::class, fn ($e) => $e->context === 'dealer' && $e->user->id === $dealer->id);
});

it('rejecting a dealer dispatches AccountRejected event', function () {
    Event::fake([AccountRejected::class]);
    Notification::fake();

    $admin  = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');
    $dealer = User::factory()->create(['status' => 'pending_activation', 'account_type' => 'dealer']);
    $dealer->assignRole('dealer');
    $dealer->dealerProfile()->create([
        'company_name'    => 'Test Motors',
        'dealer_license'  => 'DLR-001',
        'approval_status' => 'pending',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/dealers/{$dealer->id}/reject", ['reason' => 'Invalid docs'])
        ->assertStatus(200);

    Event::assertDispatched(AccountRejected::class, fn ($e) => $e->context === 'dealer' && $e->reason === 'Invalid docs');
});

it('approving a business dispatches AccountApproved event', function () {
    Event::fake([AccountApproved::class]);
    Notification::fake();

    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');
    $user  = User::factory()->create(['status' => 'pending_activation', 'account_type' => 'business']);
    $user->businessProfile()->create(['company_name' => 'Acme Corp', 'approval_status' => 'pending']);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/businesses/{$user->id}/approve")
        ->assertStatus(200);

    Event::assertDispatched(AccountApproved::class, fn ($e) => $e->context === 'business');
});

it('approving a government account dispatches AccountApproved event', function () {
    Event::fake([AccountApproved::class]);
    Notification::fake();

    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');
    $gov   = User::factory()->create(['status' => 'pending', 'account_type' => 'government']);
    $gov->govProfile()->create([
        'entity_name'           => 'State of MD',
        'entity_subtype'        => 'government',
        'point_of_contact_name' => 'John Smith',
        'phone'                 => '555-000-0000',
        'address'               => '100 Main St',
        'city'                  => 'Annapolis',
        'state'                 => 'MD',
        'zip'                   => '21401',
        'approval_status'       => 'pending',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/government/{$gov->id}/approve")
        ->assertStatus(200);

    Event::assertDispatched(AccountApproved::class, fn ($e) => $e->context === 'government');
});

// ══ LISTENER → NOTIFICATION MAPPING ══════════════════════════════════════════

it('SendAccountApprovedNotification listener sends AccountApprovedNotification', function () {
    Notification::fake();

    $user  = User::factory()->create();
    $event = new AccountApproved($user, 'dealer');

    (new SendAccountApprovedNotification())->handle($event);

    Notification::assertSentTo($user, AccountApprovedNotification::class);
});

it('SendAccountRejectedNotification listener sends AccountRejectedNotification', function () {
    Notification::fake();

    $user  = User::factory()->create();
    $event = new AccountRejected($user, 'business', 'Bad docs');

    (new SendAccountRejectedNotification())->handle($event);

    Notification::assertSentTo($user, AccountRejectedNotification::class);
});

it('SendPOAApprovedNotification listener sends PoaApprovedNotification', function () {
    Notification::fake();

    $user = User::factory()->create();
    $poa  = PowerOfAttorney::create([
        'user_id' => $user->id,
        'type'    => 'esign',
        'status'  => 'approved',
    ]);
    $event = new POAApproved($user, $poa);

    (new SendPOAApprovedNotification())->handle($event);

    Notification::assertSentTo($user, PoaApprovedNotification::class);
});

it('SendPOARejectedNotification listener sends PoaRejectedNotification', function () {
    Notification::fake();

    $user = User::factory()->create();
    $poa  = PowerOfAttorney::create([
        'user_id' => $user->id,
        'type'    => 'esign',
        'status'  => 'rejected',
    ]);
    $event = new POARejected($user, $poa, 'Signature not valid');

    (new SendPOARejectedNotification())->handle($event);

    Notification::assertSentTo($user, PoaRejectedNotification::class);
});

it('SendDocumentStatusNotification listener sends DocumentStatusUpdatedNotification to document owner', function () {
    Notification::fake();

    $user = User::factory()->create();
    $doc  = UserDocument::create([
        'user_id'       => $user->id,
        'type'          => 'driver_license',
        'status'        => 'approved',
        'disk'          => 'public',
        'file_path'     => 'docs/test.jpg',
        'original_name' => 'license.jpg',
        'mime_type'     => 'image/jpeg',
        'size_bytes'    => 10000,
    ]);
    $event = new DocumentStatusUpdated($doc);

    (new SendDocumentStatusNotification())->handle($event);

    Notification::assertSentTo($user, DocumentStatusUpdatedNotification::class);
});

it('SendDocumentStatusNotification skips when document has no owner', function () {
    Notification::fake();

    $doc = new UserDocument([
        'type'   => 'driver_license',
        'status' => 'approved',
    ]);
    $event = new DocumentStatusUpdated($doc);

    (new SendDocumentStatusNotification())->handle($event);

    Notification::assertNothingSent();
});

// ══ OUTBID DEDUPLICATION ══════════════════════════════════════════════════════

it('SendOutbidEmailNotification suppresses duplicate within 90 seconds', function () {
    Notification::fake();
    Cache::flush();

    $user = User::factory()->create();

    $creator = User::factory()->create(['status' => 'active']);
    $seller  = User::factory()->create(['status' => 'active', 'account_type' => 'individual']);
    $seller->assignRole('buyer');
    $vehicle = Vehicle::create([
        'seller_id' => $seller->id,
        'vin'       => strtoupper(fake()->unique()->lexify('?????????????????')),
        'year'      => 2021, 'make' => 'Honda', 'model' => 'Accord', 'status' => 'in_auction',
    ]);
    $auction = Auction::create([
        'title' => 'Test Auction', 'location' => 'NY', 'starts_at' => now()->subHour(),
        'status' => AuctionStatus::Live, 'created_by' => $creator->id,
    ]);
    $lot = AuctionLot::create([
        'auction_id' => $auction->id, 'vehicle_id' => $vehicle->id,
        'lot_number' => 1, 'status' => LotStatus::Open,
        'starting_bid' => 1000, 'current_bid' => 5000, 'bid_count' => 1,
    ]);

    $broadcastEvent = new OutbidBroadcastEvent($lot, $user);

    $listener = new SendOutbidEmailNotification();

    // First call — should send
    $listener->handle($broadcastEvent);
    Notification::assertSentTo($user, OutbidEmailNotification::class);

    // Second call within window — should NOT send again
    Notification::fake(); // reset
    $listener->handle($broadcastEvent);
    Notification::assertNothingSent();
});

it('SendOutbidEmailNotification allows sending again after cache expires', function () {
    Notification::fake();
    Cache::flush();

    $user    = User::factory()->create();
    $creator3 = User::factory()->create(['status' => 'active']);
    $seller3  = User::factory()->create(['status' => 'active', 'account_type' => 'individual']);
    $seller3->assignRole('buyer');
    $vehicle3 = Vehicle::create([
        'seller_id' => $seller3->id,
        'vin'       => strtoupper(fake()->unique()->lexify('?????????????????')),
        'year'      => 2020, 'make' => 'BMW', 'model' => 'X5', 'status' => 'in_auction',
    ]);
    $auction3 = Auction::create([
        'title' => 'Test Auction 3', 'location' => 'TX', 'starts_at' => now()->subHour(),
        'status' => AuctionStatus::Live, 'created_by' => $creator3->id,
    ]);
    $lot = AuctionLot::create([
        'auction_id' => $auction3->id, 'vehicle_id' => $vehicle3->id,
        'lot_number' => 3, 'status' => LotStatus::Open,
        'starting_bid' => 1000, 'current_bid' => 5000, 'bid_count' => 1,
    ]);

    // Manually clear the cache for this pair (simulate window expiry)
    $cacheKey = "outbid_notified_{$user->id}_{$lot->id}";
    Cache::forget($cacheKey);

    $broadcastEvent = new OutbidBroadcastEvent($lot, $user);
    (new SendOutbidEmailNotification())->handle($broadcastEvent);

    Notification::assertSentTo($user, OutbidEmailNotification::class);
});

// ══ NOTIFICATION PAYLOAD SHAPE ════════════════════════════════════════════════

it('AccountApprovedNotification toDatabase returns standardized payload', function () {
    $user         = User::factory()->create();
    $notification = new AccountApprovedNotification('dealer');
    $data         = $notification->toDatabase($user);

    expect($data)->toHaveKeys(['type', 'title', 'message', 'action_url', 'meta']);
    expect($data['type'])->toBe('account_approved');
    expect($data['meta']['context'])->toBe('dealer');
});

it('AccountRejectedNotification toDatabase returns standardized payload with reason in meta', function () {
    $user         = User::factory()->create();
    $notification = new AccountRejectedNotification('Missing documents', 'business');
    $data         = $notification->toDatabase($user);

    expect($data)->toHaveKeys(['type', 'title', 'message', 'action_url', 'meta']);
    expect($data['type'])->toBe('account_rejected');
    expect($data['meta']['reason'])->toBe('Missing documents');
});

it('OutbidEmailNotification toDatabase returns standardized payload with lot meta', function () {
    $creator2 = User::factory()->create(['status' => 'active']);
    $seller   = User::factory()->create(['status' => 'active', 'account_type' => 'individual']);
    $seller->assignRole('buyer');
    $vehicle2 = Vehicle::create([
        'seller_id' => $seller->id,
        'vin'       => strtoupper(fake()->unique()->lexify('?????????????????')),
        'year'      => 2022, 'make' => 'Ford', 'model' => 'F-150', 'status' => 'in_auction',
    ]);
    $auction2 = Auction::create([
        'title' => 'Test Auction 2', 'location' => 'CA', 'starts_at' => now()->subHour(),
        'status' => AuctionStatus::Live, 'created_by' => $creator2->id,
    ]);
    $lot = AuctionLot::create([
        'auction_id' => $auction2->id, 'vehicle_id' => $vehicle2->id,
        'lot_number' => 2, 'status' => LotStatus::Open,
        'starting_bid' => 1000, 'current_bid' => 7500, 'bid_count' => 1,
    ]);

    $user         = User::factory()->create();
    $notification = new OutbidEmailNotification($lot, 7500);
    $data         = $notification->toDatabase($user);

    expect($data)->toHaveKeys(['type', 'title', 'message', 'action_url', 'meta']);
    expect($data['type'])->toBe('outbid');
    expect($data['meta']['new_bid'])->toBe(7500);
});
