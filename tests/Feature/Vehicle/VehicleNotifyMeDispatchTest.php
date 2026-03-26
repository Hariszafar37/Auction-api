<?php

use App\Jobs\Auction\NotifyVehicleSubscribers;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\DealerProfile;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleNotificationSubscription;
use App\Notifications\VehicleGoingToAuction;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

// ── Helpers ───────────────────────────────────────────────────────────────

function makeAdminForNotify(): User
{
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');
    return $admin;
}

function makeActiveDraftAuction(): Auction
{
    $admin = makeAdminForNotify();
    return Auction::create([
        'title'      => 'Test Auction',
        'location'   => 'Phoenix, AZ',
        'timezone'   => 'America/Phoenix',
        'starts_at'  => now()->addDays(3),
        'status'     => 'draft',
        'created_by' => $admin->id,
    ]);
}

function makeAvailableVehicle(): Vehicle
{
    $seller = User::factory()->create(['status' => 'active']);
    return Vehicle::factory()->available()->create(['seller_id' => $seller->id]);
}

// ── Job dispatch when lot is added ────────────────────────────────────────

it('dispatches NotifyVehicleSubscribers job when a lot is added via admin API', function () {
    Queue::fake();

    $admin   = makeAdminForNotify();
    $auction = makeActiveDraftAuction();
    $vehicle = makeAvailableVehicle();

    // Publish auction so lots can be added (draft → scheduled)
    $auction->update(['status' => 'scheduled']);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/auctions/{$auction->id}/lots", [
            'vehicle_id'               => $vehicle->id,
            'starting_bid'             => 500,
            'reserve_price'            => 1000,
            'countdown_seconds'        => 60,
            'requires_seller_approval' => false,
        ])
        ->assertStatus(201);

    Queue::assertPushed(NotifyVehicleSubscribers::class);
});

it('does not dispatch job when lot add fails validation', function () {
    Queue::fake();

    $admin   = makeAdminForNotify();
    $auction = makeActiveDraftAuction();
    $auction->update(['status' => 'scheduled']);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/auctions/{$auction->id}/lots", [
            // missing required fields
        ])
        ->assertStatus(422);

    Queue::assertNotPushed(NotifyVehicleSubscribers::class);
});

// ── Job handle: sends notification ───────────────────────────────────────

it('sends VehicleGoingToAuction notification to unnotified subscribers', function () {
    Notification::fake();

    $vehicle = makeAvailableVehicle();
    $auction = makeActiveDraftAuction();

    $buyer1 = User::factory()->create(['status' => 'active']);
    $buyer2 = User::factory()->create(['status' => 'active']);

    VehicleNotificationSubscription::create(['vehicle_id' => $vehicle->id, 'user_id' => $buyer1->id]);
    VehicleNotificationSubscription::create(['vehicle_id' => $vehicle->id, 'user_id' => $buyer2->id]);

    (new NotifyVehicleSubscribers($vehicle->id, $auction->id))->handle();

    Notification::assertSentTo($buyer1, VehicleGoingToAuction::class);
    Notification::assertSentTo($buyer2, VehicleGoingToAuction::class);
});

it('does not send notification to already-notified subscribers', function () {
    Notification::fake();

    $vehicle = makeAvailableVehicle();
    $auction = makeActiveDraftAuction();

    $buyer = User::factory()->create(['status' => 'active']);
    VehicleNotificationSubscription::create([
        'vehicle_id'  => $vehicle->id,
        'user_id'     => $buyer->id,
        'notified_at' => now()->subHour(),
    ]);

    (new NotifyVehicleSubscribers($vehicle->id, $auction->id))->handle();

    Notification::assertNothingSent();
});

it('marks subscriptions as notified after sending', function () {
    Notification::fake();

    $vehicle = makeAvailableVehicle();
    $auction = makeActiveDraftAuction();

    $buyer = User::factory()->create(['status' => 'active']);
    $sub   = VehicleNotificationSubscription::create([
        'vehicle_id' => $vehicle->id,
        'user_id'    => $buyer->id,
    ]);

    expect($sub->notified_at)->toBeNull();

    (new NotifyVehicleSubscribers($vehicle->id, $auction->id))->handle();

    expect($sub->fresh()->notified_at)->not->toBeNull();
});

it('skips silently when vehicle does not exist', function () {
    Notification::fake();

    $auction = makeActiveDraftAuction();

    // Should not throw
    (new NotifyVehicleSubscribers(99999, $auction->id))->handle();

    Notification::assertNothingSent();
});

it('skips silently when auction does not exist', function () {
    Notification::fake();

    $vehicle = makeAvailableVehicle();

    (new NotifyVehicleSubscribers($vehicle->id, 99999))->handle();

    Notification::assertNothingSent();
});

it('skips subscription with deleted user gracefully', function () {
    Notification::fake();

    $vehicle = makeAvailableVehicle();
    $auction = makeActiveDraftAuction();

    $buyer = User::factory()->create(['status' => 'active']);
    VehicleNotificationSubscription::create([
        'vehicle_id' => $vehicle->id,
        'user_id'    => $buyer->id,
    ]);

    // Delete the user to simulate orphaned subscription
    $buyer->delete();

    // Should not throw
    (new NotifyVehicleSubscribers($vehicle->id, $auction->id))->handle();

    Notification::assertNothingSent();
});

// ── Notify Me subscription endpoint ──────────────────────────────────────

it('authenticated buyer can subscribe to notify me', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $buyer->assignRole('buyer');
    $vehicle = makeAvailableVehicle();

    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/vehicles/{$vehicle->id}/notify")
        ->assertStatus(200);

    expect(VehicleNotificationSubscription::where('vehicle_id', $vehicle->id)
        ->where('user_id', $buyer->id)
        ->exists()
    )->toBeTrue();
});

it('subscription is idempotent — duplicate subscribe does not create extra rows', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $buyer->assignRole('buyer');
    $vehicle = makeAvailableVehicle();

    $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/vehicles/{$vehicle->id}/notify");
    $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/vehicles/{$vehicle->id}/notify");

    expect(VehicleNotificationSubscription::where('vehicle_id', $vehicle->id)
        ->where('user_id', $buyer->id)
        ->count()
    )->toBe(1);
});

it('returns 422 when subscribing to a vehicle already in auction', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $buyer->assignRole('buyer');
    $seller  = User::factory()->create(['status' => 'active']);
    $vehicle = Vehicle::factory()->inAuction()->create(['seller_id' => $seller->id]);

    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/vehicles/{$vehicle->id}/notify")
        ->assertStatus(422);
});

it('unauthenticated user cannot subscribe to notify me', function () {
    $vehicle = makeAvailableVehicle();

    $this->postJson("/api/v1/vehicles/{$vehicle->id}/notify")
        ->assertStatus(401);
});
