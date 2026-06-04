<?php

use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\Vehicle;
use Database\Seeders\DatabaseSeeder;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('demo seeders are idempotent — re-running does not duplicate rows', function () {
    // First seed
    $this->seed(DatabaseSeeder::class);

    $vehicles = Vehicle::count();
    $auctions = Auction::count();
    $lots     = AuctionLot::count();

    expect($vehicles)->toBe(15)
        ->and($auctions)->toBe(2)
        ->and($lots)->toBe(5);

    // Re-run the full seeder (simulates a redeploy that runs db:seed again)
    $this->seed(DatabaseSeeder::class);

    // Counts must be unchanged — no duplicates created on the second run.
    expect(Vehicle::count())->toBe($vehicles)
        ->and(Auction::count())->toBe($auctions)
        ->and(AuctionLot::count())->toBe($lots);
});
