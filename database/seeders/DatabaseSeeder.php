<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // 1. Roles + permissions (must run before any user is created)
            RolePermissionSeeder::class,

            // 2. Admin user (depends on roles existing)
            AdminUserSeeder::class,

            // 3. Bid increment tiers (no dependencies)
            BidIncrementSeeder::class,

            // 4. Vehicles (depends on admin user for seller_id)
            VehicleSeeder::class,

            // 5. Auctions (depends on admin user for created_by)
            AuctionSeeder::class,

            // 6. Lots (depends on vehicles + auctions)
            AuctionLotSeeder::class,
        ]);
    }
}
