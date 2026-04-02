<?php

use App\Models\SellerProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

// ── Helpers ───────────────────────────────────────────────────────────────

function makeActiveBuyer(array $overrides = []): User
{
    $user = User::factory()->create(array_merge([
        'status'       => 'active',
        'account_type' => 'individual',
    ], $overrides));
    $user->assignRole('buyer');
    return $user;
}

function sellerApplicationPayload(array $overrides = []): array
{
    return array_merge([
        'application_notes' => 'I have 5 years of experience selling vehicles.',
        'vehicle_types'     => 'sedan, suv',
        'packet_accepted'   => true,
    ], $overrides);
}

// ── Happy path ────────────────────────────────────────────────────────────

it('active individual can submit a seller application', function () {
    $user = makeActiveBuyer();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/my/seller-application', sellerApplicationPayload())
        ->assertStatus(201)
        ->assertJson(['success' => true]);

    $this->assertDatabaseHas('seller_profiles', [
        'user_id'         => $user->id,
        'approval_status' => 'pending',
    ]);
});

it('user can view their own seller application status', function () {
    $user = makeActiveBuyer();
    SellerProfile::create([
        'user_id'            => $user->id,
        'approval_status'    => 'pending',
        'application_notes'  => 'Test note.',
        'packet_accepted_at' => now(),
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/my/seller-application')
        ->assertOk()
        ->assertJsonPath('data.approval_status', 'pending');
});

it('user with no application receives null from seller-application show', function () {
    $user = makeActiveBuyer();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/my/seller-application')
        ->assertOk()
        ->assertJsonPath('data', null);
});

// ── Guards ────────────────────────────────────────────────────────────────

it('non-individual account cannot apply to become a seller', function () {
    $dealer = User::factory()->create([
        'status'       => 'active',
        'account_type' => 'dealer',
    ]);
    $dealer->assignRole('dealer');

    $this->actingAs($dealer, 'sanctum')
        ->postJson('/api/v1/my/seller-application', sellerApplicationPayload())
        ->assertStatus(422)
        ->assertJsonPath('code', 'wrong_account_type');
});

it('inactive user cannot apply to become a seller', function () {
    $user = User::factory()->create([
        'status'       => 'pending_activation',
        'account_type' => 'individual',
    ]);
    $user->assignRole('buyer');

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/my/seller-application', sellerApplicationPayload())
        ->assertStatus(422)
        ->assertJsonPath('code', 'account_not_active');
});

it('user who already has the seller role cannot apply again', function () {
    $user = makeActiveBuyer();
    $user->assignRole('seller');

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/my/seller-application', sellerApplicationPayload())
        ->assertStatus(422)
        ->assertJsonPath('code', 'already_seller');
});

it('user with a pending application cannot submit another', function () {
    $user = makeActiveBuyer();
    SellerProfile::create([
        'user_id'            => $user->id,
        'approval_status'    => 'pending',
        'application_notes'  => 'First application.',
        'packet_accepted_at' => now(),
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/my/seller-application', sellerApplicationPayload())
        ->assertStatus(422)
        ->assertJsonPath('code', 'application_pending');
});

it('application requires acceptance of seller terms', function () {
    $user = makeActiveBuyer();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/my/seller-application', [
            'application_notes' => 'Test.',
            'packet_accepted'   => false,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['packet_accepted']);
});

// ── Re-application after rejection ────────────────────────────────────────

it('user can re-apply after their application was rejected', function () {
    $user = makeActiveBuyer();
    SellerProfile::create([
        'user_id'            => $user->id,
        'approval_status'    => 'rejected',
        'rejection_reason'   => 'Incomplete notes.',
        'application_notes'  => 'Original.',
        'packet_accepted_at' => now(),
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/my/seller-application', sellerApplicationPayload([
            'application_notes' => 'Updated application with more detail.',
        ]))
        ->assertStatus(201);

    $this->assertDatabaseHas('seller_profiles', [
        'user_id'          => $user->id,
        'approval_status'  => 'pending',
        'rejection_reason' => null,
    ]);
});

// ── Auth guard ────────────────────────────────────────────────────────────

it('unauthenticated user cannot access seller application', function () {
    $this->postJson('/api/v1/my/seller-application', sellerApplicationPayload())
        ->assertStatus(401);

    $this->getJson('/api/v1/my/seller-application')
        ->assertStatus(401);
});
