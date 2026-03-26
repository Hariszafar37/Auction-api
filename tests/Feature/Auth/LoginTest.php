<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

it('logs in with valid credentials', function () {
    $user = User::factory()->create([
        'email'    => 'user@example.com',
        'password' => Hash::make('password123'),
        'status'   => 'active',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email'    => 'user@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => ['user', 'token'],
            'message',
        ])
        ->assertJson(['success' => true]);
});

it('returns token on successful login', function () {
    User::factory()->create([
        'email'    => 'user@example.com',
        'password' => Hash::make('password123'),
        'status'   => 'active',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email'    => 'user@example.com',
        'password' => 'password123',
    ]);

    expect($response->json('data.token'))->not->toBeNull()->toBeString();
});

it('rejects invalid credentials', function () {
    User::factory()->create([
        'email'    => 'user@example.com',
        'password' => Hash::make('correct-password'),
        'status'   => 'active',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email'    => 'user@example.com',
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(422);
});

it('rejects suspended accounts', function () {
    User::factory()->create([
        'email'    => 'suspended@example.com',
        'password' => Hash::make('password123'),
        'status'   => 'suspended',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email'    => 'suspended@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(403)
        ->assertJson(['success' => false, 'code' => 'account_suspended']);
});

it('rejects non-existent email', function () {
    $response = $this->postJson('/api/v1/auth/login', [
        'email'    => 'nobody@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(422);
});

it('returns 401 on unauthenticated access to me endpoint', function () {
    $response = $this->getJson('/api/v1/auth/me');

    $response->assertStatus(401)
        ->assertJson(['success' => false, 'code' => 'unauthenticated']);
});

it('returns authenticated user on me endpoint', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('buyer');

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me');

    $response->assertStatus(200)
        ->assertJsonPath('data.email', $user->email);
});

it('logs out and deletes the token from the database', function () {
    $user      = User::factory()->create(['status' => 'active']);
    $newToken  = $user->createToken('test');

    $this->withToken($newToken->plainTextToken)->postJson('/api/v1/auth/logout')
        ->assertStatus(200)
        ->assertJson(['success' => true]);

    // Token row should be removed from DB
    $this->assertDatabaseMissing('personal_access_tokens', [
        'id' => $newToken->accessToken->id,
    ]);
});
