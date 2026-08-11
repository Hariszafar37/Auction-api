<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
});

/**
 * Create a user with an explicit id so the --after-id boundary can be tested.
 */
function userWithId(int $id, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->forceFill(['id' => $id])->save();

    return User::find($id);
}

it('deletes nothing without --force', function () {
    $spam = userWithId(120, ['email' => 'spam@example.com']);

    $this->artisan('users:purge-spam', ['--after-id' => 40])
        ->expectsOutputToContain('DRY RUN')
        ->assertSuccessful();

    $this->assertDatabaseHas('users', ['id' => $spam->id]);
});

it('never touches users at or below the boundary', function () {
    $keeper = userWithId(40, ['email' => 'legit@example.com']);
    $spam   = userWithId(41, ['email' => 'spam@example.com']);

    $this->artisan('users:purge-spam', [
        '--after-id'       => 40,
        '--force'          => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    $this->assertDatabaseHas('users', ['id' => $keeper->id]);
    $this->assertDatabaseMissing('users', ['id' => $spam->id]);
});

it('aborts when the operator declines the confirmation', function () {
    $spam = userWithId(160, ['email' => 'spam@example.com']);

    $this->artisan('users:purge-spam', ['--after-id' => 40, '--force' => true])
        ->expectsConfirmation('Permanently delete 1 user(s)? This cannot be undone.', 'no')
        ->assertSuccessful();

    $this->assertDatabaseHas('users', ['id' => $spam->id]);
});

it('deletes once the operator confirms', function () {
    $spam = userWithId(161, ['email' => 'spam@example.com']);

    $this->artisan('users:purge-spam', ['--after-id' => 40, '--force' => true])
        ->expectsConfirmation('Permanently delete 1 user(s)? This cannot be undone.', 'yes')
        ->assertSuccessful();

    $this->assertDatabaseMissing('users', ['id' => $spam->id]);
});

it('preserves any user explicitly kept', function () {
    $spam   = userWithId(101, ['email' => 'spam@example.com']);
    $keeper = userWithId(102, ['email' => 'realcustomer@example.com']);

    $this->artisan('users:purge-spam', [
        '--after-id'       => 40,
        '--keep'           => [$keeper->id],
        '--force'          => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    $this->assertDatabaseMissing('users', ['id' => $spam->id]);
    $this->assertDatabaseHas('users', ['id' => $keeper->id]);
});

it('refuses to delete a user who has real activity', function () {
    $active = userWithId(150, ['email' => 'realcustomer@example.com']);
    $spam   = userWithId(151, ['email' => 'spam@example.com']);

    // Deliberately a cascadeOnDelete relationship: without the activity check the
    // delete would succeed and silently take this row with it. Nothing would error;
    // the history would simply be gone.
    $vehicle = \App\Models\Vehicle::factory()->create();

    DB::table('vehicle_notification_subscriptions')->insert([
        'vehicle_id' => $vehicle->id,
        'user_id'    => $active->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('users:purge-spam', [
        '--after-id'       => 40,
        '--force'          => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    $this->assertDatabaseHas('users', ['id' => $active->id]);
    $this->assertDatabaseHas('vehicle_notification_subscriptions', ['user_id' => $active->id]);
    $this->assertDatabaseMissing('users', ['id' => $spam->id]);
});

it('refuses to delete privileged accounts whatever their id', function () {
    $admin = userWithId(200, ['email' => 'newadmin@example.com']);
    $admin->assignRole('admin');

    $this->artisan('users:purge-spam', ['--after-id' => 40, '--force' => true])
        ->assertSuccessful();

    $this->assertDatabaseHas('users', ['id' => $admin->id]);
});

it('writes a reviewable CSV of every candidate', function () {
    userWithId(130, ['email' => 'spam@example.com']);

    $path = storage_path('app/purge-review-test.csv');
    @unlink($path);

    $this->artisan('users:purge-spam', ['--after-id' => 40, '--export' => $path])
        ->assertSuccessful();

    expect(file_exists($path))->toBeTrue();

    $csv = file_get_contents($path);
    expect($csv)->toContain('spam@example.com')->toContain('delete');

    @unlink($path);
});
