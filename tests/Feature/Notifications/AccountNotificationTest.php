<?php

use App\Models\DealerProfile;
use App\Models\User;
use App\Models\GovProfile;
use App\Models\SellerProfile;
use App\Models\BusinessProfile;
use App\Notifications\AccountApprovedNotification;
use App\Notifications\AccountRejectedNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Notification::fake();

    $this->admin = User::factory()->create(['status' => 'active']);
    $this->admin->assignRole('admin');
});

// ══ DEALER ════════════════════════════════════════════════════════════════════

it('approving a dealer sends AccountApprovedNotification', function () {
    $dealer = User::factory()->create(['status' => 'pending_activation', 'account_type' => 'dealer']);
    $dealer->assignRole('dealer');
    DealerProfile::create([
        'user_id'         => $dealer->id,
        'company_name'    => 'Test Motors',
        'dealer_license'  => 'DLR-001',
        'approval_status' => 'pending',
    ]);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/admin/dealers/{$dealer->id}/approve")
        ->assertStatus(200);

    Notification::assertSentTo($dealer, AccountApprovedNotification::class, function ($n) {
        return $n->toDatabase($n)['context'] === 'dealer';
    });
});

it('rejecting a dealer sends AccountRejectedNotification with reason', function () {
    $dealer = User::factory()->create(['status' => 'pending_activation', 'account_type' => 'dealer']);
    $dealer->assignRole('dealer');
    DealerProfile::create([
        'user_id'         => $dealer->id,
        'company_name'    => 'Test Motors',
        'dealer_license'  => 'DLR-001',
        'approval_status' => 'pending',
    ]);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/admin/dealers/{$dealer->id}/reject", ['reason' => 'Missing documentation.'])
        ->assertStatus(200);

    Notification::assertSentTo($dealer, AccountRejectedNotification::class, function ($n) {
        $data = $n->toDatabase($n);
        return $data['context'] === 'dealer' && $data['reason'] === 'Missing documentation.';
    });
});

// ══ BUSINESS ══════════════════════════════════════════════════════════════════

it('approving a business sends AccountApprovedNotification', function () {
    $user = User::factory()->create(['status' => 'pending_activation', 'account_type' => 'business']);
    BusinessProfile::create([
        'user_id'         => $user->id,
        'company_name'    => 'Acme Corp',
        'approval_status' => 'pending',
    ]);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/admin/businesses/{$user->id}/approve")
        ->assertStatus(200);

    Notification::assertSentTo($user, AccountApprovedNotification::class, function ($n) {
        return $n->toDatabase($n)['context'] === 'business';
    });
});

it('rejecting a business sends AccountRejectedNotification', function () {
    $user = User::factory()->create(['status' => 'pending_activation', 'account_type' => 'business']);
    BusinessProfile::create([
        'user_id'         => $user->id,
        'company_name'    => 'Acme Corp',
        'approval_status' => 'pending',
    ]);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/admin/businesses/{$user->id}/reject", ['reason' => 'Invalid documents.'])
        ->assertStatus(200);

    Notification::assertSentTo($user, AccountRejectedNotification::class, function ($n) {
        return $n->toDatabase($n)['context'] === 'business';
    });
});

// ══ INDIVIDUAL SELLER ═════════════════════════════════════════════════════════

it('approving a seller sends AccountApprovedNotification', function () {
    $user = User::factory()->create(['status' => 'active', 'account_type' => 'individual']);
    $user->assignRole('buyer');
    SellerProfile::create([
        'user_id'         => $user->id,
        'approval_status' => 'pending',
    ]);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/admin/sellers/{$user->id}/approve")
        ->assertStatus(200);

    Notification::assertSentTo($user, AccountApprovedNotification::class, function ($n) {
        return $n->toDatabase($n)['context'] === 'seller';
    });
});

it('rejecting a seller sends AccountRejectedNotification', function () {
    $user = User::factory()->create(['status' => 'active', 'account_type' => 'individual']);
    $user->assignRole('buyer');
    SellerProfile::create([
        'user_id'         => $user->id,
        'approval_status' => 'pending',
    ]);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/admin/sellers/{$user->id}/reject", ['reason' => null])
        ->assertStatus(200);

    Notification::assertSentTo($user, AccountRejectedNotification::class, function ($n) {
        return $n->toDatabase($n)['context'] === 'seller';
    });
});

// ══ GOVERNMENT ════════════════════════════════════════════════════════════════

it('approving a government account sends AccountApprovedNotification', function () {
    $user = User::factory()->create(['status' => 'pending', 'account_type' => 'government']);
    GovProfile::create([
        'user_id'               => $user->id,
        'entity_name'           => 'State of Maryland',
        'entity_subtype'        => 'government',
        'point_of_contact_name' => 'John Smith',
        'phone'                 => '555-000-0000',
        'address'               => '100 Main St',
        'city'                  => 'Annapolis',
        'state'                 => 'MD',
        'zip'                   => '21401',
        'approval_status'       => 'pending',
    ]);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/admin/government/{$user->id}/approve")
        ->assertStatus(200);

    Notification::assertSentTo($user, AccountApprovedNotification::class, function ($n) {
        return $n->toDatabase($n)['context'] === 'government';
    });
});

it('rejecting a government account sends AccountRejectedNotification', function () {
    $user = User::factory()->create(['status' => 'pending', 'account_type' => 'government']);
    GovProfile::create([
        'user_id'               => $user->id,
        'entity_name'           => 'State of Maryland',
        'entity_subtype'        => 'government',
        'point_of_contact_name' => 'John Smith',
        'phone'                 => '555-000-0000',
        'address'               => '100 Main St',
        'city'                  => 'Annapolis',
        'state'                 => 'MD',
        'zip'                   => '21401',
        'approval_status'       => 'pending',
    ]);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/admin/government/{$user->id}/reject", ['reason' => 'Unverifiable entity.'])
        ->assertStatus(200);

    Notification::assertSentTo($user, AccountRejectedNotification::class, function ($n) {
        return $n->toDatabase($n)['context'] === 'government';
    });
});
