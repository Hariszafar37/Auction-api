<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    Storage::fake('public');
});

// ── Helper: ready-to-activate user ───────────────────────────────────────────

function makeActivationReadyUser(string $role = 'buyer'): User
{
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'password_set_at'   => now(),
        'status'            => 'pending_activation',
    ]);
    $user->assignRole($role);
    return $user;
}

// ── Account type ─────────────────────────────────────────────────────────────

it('saves account type individual', function () {
    $user = makeActivationReadyUser();

    $this->actingAs($user)
        ->postJson('/api/v1/activation/account-type', ['account_type' => 'individual'])
        ->assertOk()
        ->assertJsonPath('data.account_type', 'individual');

    expect($user->fresh()->account_type)->toBe('individual');
});

it('saves account type dealer and assigns dealer role', function () {
    $user = makeActivationReadyUser();

    $this->actingAs($user)
        ->postJson('/api/v1/activation/account-type', ['account_type' => 'dealer'])
        ->assertOk();

    expect($user->fresh()->hasRole('dealer'))->toBeTrue();
});

it('rejects invalid account type', function () {
    $user = makeActivationReadyUser();

    $this->actingAs($user)
        ->postJson('/api/v1/activation/account-type', ['account_type' => 'unknown'])
        ->assertStatus(422);
});

// ── Account information ───────────────────────────────────────────────────────

it('saves account information', function () {
    $user = makeActivationReadyUser();

    $this->actingAs($user)->postJson('/api/v1/activation/account-information', [
        'date_of_birth'                  => '1990-01-15',
        'address'                        => '123 Main St',
        'country'                        => 'US',
        'state'                          => 'California',
        'city'                           => 'Los Angeles',
        'zip_postal_code'                => '90001',
        'driver_license_number'          => 'DL-999',
        'driver_license_expiration_date' => now()->addYears(2)->format('Y-m-d'),
    ])->assertOk();

    $this->assertDatabaseHas('user_account_information', [
        'user_id'               => $user->id,
        'driver_license_number' => 'DL-999',
        'city'                  => 'Los Angeles',
    ]);
});

it('rejects account information when dob indicates under 18', function () {
    $user = makeActivationReadyUser();

    $this->actingAs($user)->postJson('/api/v1/activation/account-information', [
        'date_of_birth'                  => now()->subYears(17)->format('Y-m-d'),
        'address'                        => '123 Main St',
        'country'                        => 'US',
        'state'                          => 'CA',
        'city'                           => 'LA',
        'zip_postal_code'                => '90001',
        'driver_license_number'          => 'DL-999',
        'driver_license_expiration_date' => now()->addYears(2)->format('Y-m-d'),
    ])->assertStatus(422)
      ->assertJsonPath('errors.date_of_birth', fn ($v) => count($v) > 0);
});

// ── Dealer information ────────────────────────────────────────────────────────

it('saves dealer information for dealer account', function () {
    $user = makeActivationReadyUser('dealer');
    $user->update(['account_type' => 'dealer']);

    $this->actingAs($user)->postJson('/api/v1/activation/dealer-information', [
        'company_name'            => 'Best Cars LLC',
        'owner_name'              => 'John Owner',
        'phone'                   => '555-999-8888',
        'primary_contact'         => 'john@bestcars.com',
        'license_number'          => 'DEALER-001',
        'license_expiration_date' => now()->addYears(3)->format('Y-m-d'),
        'dealer_address'          => '789 Lot Ave',
        'dealer_country'          => 'US',
        'dealer_city'             => 'Houston',
        'dealer_zip_code'         => '77001',
    ])->assertOk();

    $this->assertDatabaseHas('user_dealer_information', [
        'user_id'      => $user->id,
        'company_name' => 'Best Cars LLC',
    ]);
});

it('rejects dealer information for non-dealer account', function () {
    $user = makeActivationReadyUser();
    $user->update(['account_type' => 'individual']);

    $this->actingAs($user)->postJson('/api/v1/activation/dealer-information', [
        'company_name'            => 'Test Corp',
        'owner_name'              => 'Jane',
        'phone'                   => '555-000',
        'primary_contact'         => 'jane@corp.com',
        'license_number'          => 'LIC-001',
        'license_expiration_date' => now()->addYear()->format('Y-m-d'),
        'dealer_address'          => '1 Corp St',
        'dealer_country'          => 'US',
        'dealer_city'             => 'NYC',
        'dealer_zip_code'         => '10001',
    ])->assertStatus(422)
      ->assertJsonPath('code', 'not_a_dealer');
});

// ── Billing information ───────────────────────────────────────────────────────

it('saves billing information', function () {
    $user = makeActivationReadyUser();

    $this->actingAs($user)->postJson('/api/v1/activation/billing-information', [
        'billing_address'         => '456 Billing Rd',
        'billing_country'         => 'US',
        'billing_city'            => 'Chicago',
        'billing_zip_postal_code' => '60601',
    ])->assertOk();

    $this->assertDatabaseHas('user_billing_information', [
        'user_id'      => $user->id,
        'billing_city' => 'Chicago',
    ]);
});

// ── Document upload ───────────────────────────────────────────────────────────

it('uploads an id document and stores on public disk', function () {
    $user = makeActivationReadyUser();

    $this->actingAs($user)->postJson('/api/v1/activation/upload-documents', [
        'document_type' => 'id',
        'file'          => UploadedFile::fake()->image('id-front.jpg'),
    ])->assertStatus(201)
      ->assertJsonPath('data.type', 'id');

    $this->assertDatabaseHas('user_documents', [
        'user_id' => $user->id,
        'type'    => 'id',
        'disk'    => 'public',
    ]);
});

it('rejects document over 2MB', function () {
    $user = makeActivationReadyUser();

    $this->actingAs($user)->postJson('/api/v1/activation/upload-documents', [
        'document_type' => 'id',
        'file'          => UploadedFile::fake()->create('big.pdf', 2049, 'application/pdf'), // 2049 KB
    ])->assertStatus(422)
      ->assertJsonPath('errors.file', fn ($v) => count($v) > 0);
});

it('rejects unsupported file type', function () {
    $user = makeActivationReadyUser();

    $this->actingAs($user)->postJson('/api/v1/activation/upload-documents', [
        'document_type' => 'id',
        'file'          => UploadedFile::fake()->create('doc.exe', 100, 'application/octet-stream'),
    ])->assertStatus(422);
});

// ── Activation complete ───────────────────────────────────────────────────────

function completeAllSteps(User $user, string $accountType = 'individual'): void
{
    $user->update(['account_type' => $accountType]);

    $user->accountInformation()->create([
        'date_of_birth'                  => '1990-05-10',
        'address'                        => '100 Test St',
        'country'                        => 'US',
        'state'                          => 'TX',
        'city'                           => 'Austin',
        'zip_postal_code'                => '73301',
        'driver_license_number'          => 'TX-DL-123',
        'driver_license_expiration_date' => now()->addYears(2)->format('Y-m-d'),
    ]);

    $user->billingInformation()->create([
        'billing_address'         => '200 Bill St',
        'billing_country'         => 'US',
        'billing_city'            => 'Austin',
        'billing_zip_postal_code' => '73301',
    ]);

    if ($accountType === 'dealer') {
        $user->dealerInformation()->create([
            'company_name'            => 'Dealer Co',
            'owner_name'              => 'Owner',
            'phone'                   => '555-111-2222',
            'primary_contact'         => 'owner@dealer.com',
            'license_number'          => 'DLR-LIC-001',
            'license_expiration_date' => now()->addYears(2)->format('Y-m-d'),
            'dealer_address'          => '300 Lot Rd',
            'dealer_country'          => 'US',
            'dealer_city'             => 'Dallas',
            'dealer_zip_code'         => '75001',
        ]);
    }

    $user->documents()->create([
        'type'          => 'id',
        'file_path'     => "user-documents/{$user->id}/id/test.jpg",
        'disk'          => 'public',
        'original_name' => 'test.jpg',
        'mime_type'     => 'image/jpeg',
        'size_bytes'    => 50000,
    ]);
}

it('completes activation for an individual and sets status to active', function () {
    $user = makeActivationReadyUser();
    completeAllSteps($user, 'individual');

    $this->actingAs($user)
        ->postJson('/api/v1/activation/complete')
        ->assertOk()
        ->assertJsonPath('data.activation_status', 'complete')
        ->assertJsonPath('data.activation_required', false);

    expect($user->fresh()->status)->toBe('active');
});

it('completes activation for a dealer and sets status to pending_activation', function () {
    $user = makeActivationReadyUser('dealer');
    completeAllSteps($user, 'dealer');

    $this->actingAs($user)
        ->postJson('/api/v1/activation/complete')
        ->assertOk()
        ->assertJsonPath('data.activation_status', 'pending_approval');

    expect($user->fresh()->status)->toBe('pending_activation');
    $this->assertDatabaseHas('dealer_profiles', [
        'user_id'         => $user->id,
        'approval_status' => 'pending',
    ]);
});

it('rejects complete when account information is missing', function () {
    $user = makeActivationReadyUser();
    $user->update(['account_type' => 'individual']);

    // billing + doc exist, but no account_information
    $user->billingInformation()->create([
        'billing_address'         => '1 St',
        'billing_country'         => 'US',
        'billing_city'            => 'X',
        'billing_zip_postal_code' => '00000',
    ]);
    $user->documents()->create([
        'type' => 'id', 'file_path' => 'x', 'disk' => 'public',
        'original_name' => 'x.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1000,
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/activation/complete')
        ->assertStatus(422)
        ->assertJsonPath('code', 'activation_incomplete');
});

it('rejects complete when no document has been uploaded', function () {
    $user = makeActivationReadyUser();
    $user->update(['account_type' => 'individual']);
    $user->accountInformation()->create([
        'date_of_birth' => '1990-01-01', 'address' => '1 St', 'country' => 'US',
        'state' => 'TX', 'city' => 'X', 'zip_postal_code' => '00000',
        'driver_license_number' => 'DL-1',
        'driver_license_expiration_date' => now()->addYear()->format('Y-m-d'),
    ]);
    $user->billingInformation()->create([
        'billing_address' => '1 St', 'billing_country' => 'US',
        'billing_city' => 'X', 'billing_zip_postal_code' => '00000',
    ]);
    // No document uploaded

    $this->actingAs($user)
        ->postJson('/api/v1/activation/complete')
        ->assertStatus(422)
        ->assertJsonPath('code', 'activation_incomplete');
});
