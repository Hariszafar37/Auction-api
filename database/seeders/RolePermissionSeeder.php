<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // --- Permissions ---
        $permissions = [
            // Auction
            'auctions.view',
            'auctions.bid',
            'auctions.create',
            'auctions.manage',

            // Inventory
            'inventory.view',
            'inventory.create',
            'inventory.manage',

            // Users
            'users.view',
            'users.manage',

            // Dealers
            'dealers.view',
            'dealers.approve',

            // Sellers (individual seller applications)
            'sellers.view',
            'sellers.approve',

            // Payments
            'payments.view',
            'payments.manage',

            // Reports / CMS
            'reports.view',
            'cms.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'sanctum']);
        }

        // --- Roles ---
        $buyer  = Role::firstOrCreate(['name' => 'buyer',  'guard_name' => 'sanctum']);
        $dealer = Role::firstOrCreate(['name' => 'dealer', 'guard_name' => 'sanctum']);
        $seller = Role::firstOrCreate(['name' => 'seller', 'guard_name' => 'sanctum']);
        $staff  = Role::firstOrCreate(['name' => 'staff',  'guard_name' => 'sanctum']);
        $admin  = Role::firstOrCreate(['name' => 'admin',  'guard_name' => 'sanctum']);

        $buyer->syncPermissions([
            'auctions.view',
            'auctions.bid',
            'inventory.view',
            'payments.view',
        ]);

        $dealer->syncPermissions([
            'auctions.view',
            'auctions.bid',
            'auctions.create',
            'inventory.view',
            'inventory.create',
            'inventory.manage',
            'payments.view',
        ]);

        // Seller role: approved individual sellers can list and manage vehicles.
        // Intentionally mirrors dealer permissions — both use the same vehicle routes,
        // now protected by permission:inventory.create instead of role:dealer.
        $seller->syncPermissions([
            'auctions.view',
            'auctions.bid',
            'auctions.create',
            'inventory.view',
            'inventory.create',
            'inventory.manage',
            'payments.view',
        ]);

        $staff->syncPermissions([
            'auctions.view',
            'auctions.manage',
            'inventory.view',
            'inventory.manage',
            'users.view',
            'dealers.view',
            'sellers.view',
            'payments.view',
            'payments.manage',
            'reports.view',
        ]);

        $admin->syncPermissions($permissions);
    }
}
