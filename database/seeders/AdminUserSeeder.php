<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // Clear permission cache so role assignment works cleanly after RolePermissionSeeder
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name'                    => 'Admin User',
                'first_name'              => 'Admin',
                'last_name'               => 'User',
                'password'                => Hash::make('password'),
                'status'                  => 'active',
                'account_type'            => 'individual',
                'agreed_terms_at'         => now(),
                'password_set_at'         => now(),
                'activation_completed_at' => now(),
            ]
        );

        // Not in the attribute array above: `email_verified_at` is absent from
        // User::$fillable, so firstOrCreate() filters it out silently and the
        // seeded admin ends up active but unverified — which trips every
        // hasVerifiedEmail() check. Guarded so re-running does not move the
        // timestamp on an already-verified account.
        if (! $admin->hasVerifiedEmail()) {
            $admin->markEmailAsVerified();
        }

        // Ensure admin role is assigned (idempotent — safe to re-run)
        $admin->syncRoles(['admin']);

        $this->command->info("Admin user ready — email: admin@example.com / password: password");
    }
}
