<?php

use App\Enums\AuctionStatus;
use App\Enums\LotStatus;
use App\Models\AuctionLot;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

/**
 * Admins may manually edit a lot's number after assignment (Issue #7), but the
 * number must stay unique within its auction.
 */
function makeLotAdmin(): User
{
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    return $admin;
}

function pendingLot(int $auctionId, int $vehicleId, int $lotNumber): AuctionLot
{
    return AuctionLot::create([
        'auction_id'               => $auctionId,
        'vehicle_id'               => $vehicleId,
        'lot_number'               => $lotNumber,
        'status'                   => LotStatus::Pending,
        'starting_bid'             => 500,
        'reserve_price'            => null,
        'current_bid'              => null,
        'bid_count'                => 0,
        'countdown_seconds'        => 30,
        'requires_seller_approval' => false,
    ]);
}

it('lets an admin change a pending lot number', function () {
    $auction = $this->createAuction(['status' => AuctionStatus::Scheduled]);
    $lot     = pendingLot($auction->id, $this->createVehicle()->id, 1);

    $this->actingAs(makeLotAdmin(), 'sanctum')
        ->patchJson("/api/v1/admin/auctions/{$auction->id}/lots/{$lot->id}", ['lot_number' => 7])
        ->assertOk();

    $this->assertDatabaseHas('auction_lots', ['id' => $lot->id, 'lot_number' => 7]);
});

it('rejects a duplicate lot number within the same auction', function () {
    $auction = $this->createAuction(['status' => AuctionStatus::Scheduled]);
    pendingLot($auction->id, $this->createVehicle()->id, 1);
    $lot2 = pendingLot($auction->id, $this->createVehicle()->id, 2);

    $this->actingAs(makeLotAdmin(), 'sanctum')
        ->patchJson("/api/v1/admin/auctions/{$auction->id}/lots/{$lot2->id}", ['lot_number' => 1])
        ->assertStatus(422)
        ->assertJsonValidationErrors('lot_number');
});

it('allows saving a lot with its own unchanged number', function () {
    $auction = $this->createAuction(['status' => AuctionStatus::Scheduled]);
    $lot     = pendingLot($auction->id, $this->createVehicle()->id, 3);

    // Re-submitting the same number must not trip the uniqueness rule (self-ignore).
    $this->actingAs(makeLotAdmin(), 'sanctum')
        ->patchJson("/api/v1/admin/auctions/{$auction->id}/lots/{$lot->id}", ['lot_number' => 3])
        ->assertOk();
});
