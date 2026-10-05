<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Government consignors become a restricted seller instead of a buyer.
 *
 * Creates the `government` role here rather than relying on
 * RolePermissionSeeder: deploys run migrations but never the seeder, so a role
 * that only the seeder creates would not exist in production. The seeder
 * declares the same role and permissions — keep the two in step.
 *
 * Existing government accounts are converted: the `buyer` role is swapped for
 * `government` and bidding is switched off (a government consignor sells, it
 * does not bid). Other account types are not touched.
 */
return new class extends Migration
{
    private const GUARD = 'sanctum';

    private const PERMISSIONS = [
        'auctions.view',
        'inventory.view',
        'inventory.create',
        'inventory.manage',
        'payments.view',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => self::GUARD]);
        }

        $government = Role::firstOrCreate(['name' => 'government', 'guard_name' => self::GUARD]);
        $government->syncPermissions(self::PERMISSIONS);

        $buyer = Role::where('name', 'buyer')->where('guard_name', self::GUARD)->first();

        $userIds = DB::table('users')->where('account_type', 'government')->pluck('id');

        foreach ($userIds as $userId) {
            DB::table('model_has_roles')->insertOrIgnore([
                'role_id'    => $government->id,
                'model_type' => \App\Models\User::class,
                'model_id'   => $userId,
            ]);

            if ($buyer) {
                DB::table('model_has_roles')
                    ->where('role_id', $buyer->id)
                    ->where('model_type', \App\Models\User::class)
                    ->where('model_id', $userId)
                    ->delete();
            }
        }

        if ($userIds->isNotEmpty()) {
            DB::table('users')->whereIn('id', $userIds)->update(['bidding_enabled' => false]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $government = Role::where('name', 'government')->where('guard_name', self::GUARD)->first();
        $buyer      = Role::where('name', 'buyer')->where('guard_name', self::GUARD)->first();

        $userIds = DB::table('users')->where('account_type', 'government')->pluck('id');

        foreach ($userIds as $userId) {
            if ($buyer) {
                DB::table('model_has_roles')->insertOrIgnore([
                    'role_id'    => $buyer->id,
                    'model_type' => \App\Models\User::class,
                    'model_id'   => $userId,
                ]);
            }
        }

        if ($userIds->isNotEmpty()) {
            DB::table('users')->whereIn('id', $userIds)->update(['bidding_enabled' => true]);
        }

        // Removed through the tables rather than Role::delete(): the model's
        // delete hook resolves a user model from the guard, and the `sanctum`
        // guard has no provider, so it throws. The shared permissions are left
        // in place — other roles hold them.
        if ($government) {
            DB::table('model_has_roles')->where('role_id', $government->id)->delete();
            DB::table('role_has_permissions')->where('role_id', $government->id)->delete();
            DB::table('roles')->where('id', $government->id)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
