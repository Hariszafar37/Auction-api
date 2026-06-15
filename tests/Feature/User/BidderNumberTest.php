<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

it('auto-assigns a unique bidder number when a user is created', function () {
    $user = User::factory()->create();

    expect($user->fresh()->bidder_number)->toBe(10000 + $user->id);
});

it('assigns a bidder number through the registration endpoint', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'first_name'               => 'Jane',
        'last_name'                => 'Doe',
        'email'                    => 'jane.bidder@example.com',
        'email_confirmation'       => 'jane.bidder@example.com',
        'primary_phone'            => '5551234567',
        'agree_terms'              => true,
        'agree_ecomm_consent'      => true,
        'agree_accuracy_confirmed' => true,
    ]);

    $response->assertStatus(201);

    $user = User::where('email', 'jane.bidder@example.com')->first();
    expect($user->bidder_number)->not->toBeNull();
});

it('exposes the bidder number on the auth/me resource', function () {
    $user = User::factory()->create(['status' => 'active']);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me');

    $response->assertSuccessful()
        ->assertJsonPath('data.bidder_number', $user->fresh()->bidder_number);
});

it('lets an admin update a user bidder number', function () {
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    $target = User::factory()->create(['status' => 'active']);

    $response = $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$target->id}", [
            'bidder_number' => 987654,
        ]);

    $response->assertSuccessful();
    expect($target->fresh()->bidder_number)->toBe(987654);
});

it('rejects a duplicate bidder number on admin update', function () {
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    $existing = User::factory()->create(['status' => 'active']);
    $target   = User::factory()->create(['status' => 'active']);

    $response = $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$target->id}", [
            'bidder_number' => $existing->fresh()->bidder_number,
        ]);

    $response->assertStatus(422);
});
