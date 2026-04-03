<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Notification::fake();
});

it('authenticated user can fetch their notifications', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('buyer');

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/notifications')
        ->assertStatus(200)
        ->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'total', 'unread_count'],
        ]);
});

it('unauthenticated user cannot fetch notifications', function () {
    $this->getJson('/api/v1/notifications')
        ->assertStatus(401);
});

it('authenticated user can get their unread count', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('buyer');

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/notifications/unread-count')
        ->assertStatus(200)
        ->assertJsonStructure(['data' => ['unread_count']]);
});

it('user can mark all notifications as read', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('buyer');

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/notifications/read-all')
        ->assertStatus(200);
});

it('user can mark a single notification as read', function () {
    $user = User::factory()->create(['status' => 'active']);

    // Create a real database notification record
    $id = \Ramsey\Uuid\Uuid::uuid4()->toString();
    \Illuminate\Support\Facades\DB::table('notifications')->insert([
        'id'              => $id,
        'type'            => 'App\Notifications\AccountApprovedNotification',
        'notifiable_type' => User::class,
        'notifiable_id'   => $user->id,
        'data'            => json_encode(['type' => 'account_approved', 'message' => 'test']),
        'read_at'         => null,
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/notifications/{$id}/read")
        ->assertStatus(200)
        ->assertJsonPath('data.read_at', fn ($v) => $v !== null);
});

it('user cannot mark another user notification as read', function () {
    $userA = User::factory()->create(['status' => 'active']);
    $userB = User::factory()->create(['status' => 'active']);

    $id = \Ramsey\Uuid\Uuid::uuid4()->toString();
    \Illuminate\Support\Facades\DB::table('notifications')->insert([
        'id'              => $id,
        'type'            => 'App\Notifications\AccountApprovedNotification',
        'notifiable_type' => User::class,
        'notifiable_id'   => $userA->id,
        'data'            => json_encode(['type' => 'account_approved', 'message' => 'test']),
        'read_at'         => null,
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    // userB cannot read userA's notification
    $this->actingAs($userB, 'sanctum')
        ->postJson("/api/v1/notifications/{$id}/read")
        ->assertStatus(404);
});
