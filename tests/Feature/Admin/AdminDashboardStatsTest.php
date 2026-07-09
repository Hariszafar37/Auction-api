<?php

use App\Models\BusinessProfile;
use App\Models\DealerProfile;
use App\Models\GovProfile;
use App\Models\PowerOfAttorney;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function dashboardStatsAdmin(): User
{
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    return $admin;
}

it('blocks non-admin users from the dashboard stats endpoint', function () {
    $buyer = User::factory()->create(['status' => 'active']);
    $buyer->assignRole('buyer');

    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/admin/dashboard/stats')
        ->assertStatus(403);
});

it('returns the full pending action-count block with every key', function () {
    $response = $this->actingAs(dashboardStatsAdmin(), 'sanctum')
        ->getJson('/api/v1/admin/dashboard/stats');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                'users', 'vehicles', 'auctions', 'live_auctions', 'invoices',
                'total_revenue', 'pending_revenue', 'bids',
                'pending' => [
                    'user_applications',
                    'dealer_applications',
                    'seller_applications',
                    'business_applications',
                    'government_applications',
                    'poa_approvals',
                    'payments',
                    'titles',
                    'disputes',
                    'settlements',
                ],
            ],
        ]);

    // With no data seeded, every pending bucket is zero.
    foreach ($response->json('data.pending') as $count) {
        expect($count)->toBe(0);
    }
});

it('counts pending account applications by their exact statuses', function () {
    // Onboarding queue — accounts awaiting activation.
    User::factory()->count(2)->create(['status' => 'pending_activation']);
    User::factory()->create(['status' => 'active']); // control, not counted

    // Dealer: one pending, one approved (control).
    DealerProfile::create(['user_id' => User::factory()->create()->id, 'company_name' => 'A', 'dealer_license' => 'DL-1', 'approval_status' => 'pending']);
    DealerProfile::create(['user_id' => User::factory()->create()->id, 'company_name' => 'B', 'dealer_license' => 'DL-2', 'approval_status' => 'approved']);

    SellerProfile::create(['user_id' => User::factory()->create()->id, 'approval_status' => 'pending']);

    BusinessProfile::create(['user_id' => User::factory()->create()->id, 'legal_business_name' => 'Biz', 'entity_type' => 'llc', 'approval_status' => 'pending']);

    GovProfile::create([
        'user_id'               => User::factory()->create()->id,
        'entity_name'           => 'Dept',
        'entity_subtype'        => 'government',
        'point_of_contact_name' => 'Contact',
        'phone'                 => '555-000-0001',
        'address'               => '100 Rd',
        'city'                  => 'Town',
        'state'                 => 'MD',
        'zip'                   => '21201',
        'approval_status'       => 'pending',
    ]);

    $response = $this->actingAs(dashboardStatsAdmin(), 'sanctum')
        ->getJson('/api/v1/admin/dashboard/stats');

    $response->assertStatus(200)
        // 2 pending_activation applicants + the 4 profile owners (also pending_activation
        // by factory default? No — created with default 'active') → only the 2 count.
        ->assertJsonPath('data.pending.user_applications', 2)
        ->assertJsonPath('data.pending.dealer_applications', 1)
        ->assertJsonPath('data.pending.seller_applications', 1)
        ->assertJsonPath('data.pending.business_applications', 1)
        ->assertJsonPath('data.pending.government_applications', 1);
});

it('counts pending POA approvals only in the pending status', function () {
    $u1 = User::factory()->create();
    PowerOfAttorney::create(['user_id' => $u1->id, 'type' => 'esign', 'status' => 'pending']);
    $u2 = User::factory()->create();
    PowerOfAttorney::create(['user_id' => $u2->id, 'type' => 'esign', 'status' => 'approved']);

    $this->actingAs(dashboardStatsAdmin(), 'sanctum')
        ->getJson('/api/v1/admin/dashboard/stats')
        ->assertStatus(200)
        ->assertJsonPath('data.pending.poa_approvals', 1);
});

it('counts vehicles awaiting title receipt in the active pipeline', function () {
    // Owes a title, still in the pipeline → counted.
    Vehicle::factory()->create(['has_title' => true, 'title_received' => false, 'status' => 'in_auction']);
    Vehicle::factory()->create(['has_title' => true, 'title_received' => false, 'status' => 'available']);
    // Title already received → not counted.
    Vehicle::factory()->create(['has_title' => true, 'title_received' => true, 'status' => 'sold']);
    // No title on record → nothing to receive.
    Vehicle::factory()->create(['has_title' => false, 'title_received' => false, 'status' => 'available']);
    // Withdrawn → out of the active pipeline.
    Vehicle::factory()->create(['has_title' => true, 'title_received' => false, 'status' => 'withdrawn']);

    $this->actingAs(dashboardStatsAdmin(), 'sanctum')
        ->getJson('/api/v1/admin/dashboard/stats')
        ->assertStatus(200)
        ->assertJsonPath('data.pending.titles', 2);
});
