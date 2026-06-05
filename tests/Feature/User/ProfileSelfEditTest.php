<?php

use App\Models\User;

/*
|--------------------------------------------------------------------------
| Profile self-edit — active users editing their own activation sections
|--------------------------------------------------------------------------
| Covers PUT /api/v1/profile/{account,billing,business,dealer}-information.
| These endpoints have NO isActive() guard (unlike the activation wizard) and
| never touch approval snapshots or account_intent.
*/

// ── Account / contact ("shipping") information ────────────────────────────────

it('lets an active user update their contact information', function () {
    $user = User::factory()->create(['status' => 'active', 'account_type' => 'individual']);

    $this->actingAs($user, 'sanctum')
        ->putJson('/api/v1/profile/account-information', [
            'date_of_birth'   => '1990-01-15',
            'address'         => '123 Main St',
            'country'         => 'US',
            'state'           => 'Maryland',
            'city'            => 'Baltimore',
            'zip_postal_code' => '21201',
            'id_type'         => 'driver_license',
            'id_number'       => 'D1234567',
        ])
        ->assertOk()
        ->assertJsonPath('data.account_information.address', '123 Main St');

    $this->assertDatabaseHas('user_account_information', [
        'user_id' => $user->id,
        'address' => '123 Main St',
        'city'    => 'Baltimore',
    ]);
});

it('updates the existing contact record instead of creating a duplicate', function () {
    $user = User::factory()->create(['status' => 'active', 'account_type' => 'individual']);
    $user->accountInformation()->create([
        'date_of_birth'   => '1985-06-01',
        'address'         => 'Old Address',
        'country'         => 'US',
        'state'           => 'Virginia',
        'city'            => 'Richmond',
        'zip_postal_code' => '23218',
        'id_type'         => 'state_id',
        'id_number'       => 'OLD123',
    ]);

    $this->actingAs($user, 'sanctum')
        ->putJson('/api/v1/profile/account-information', [
            'date_of_birth'   => '1985-06-01',
            'address'         => 'New Address',
            'country'         => 'US',
            'state'           => 'Maryland',
            'city'            => 'Annapolis',
            'zip_postal_code' => '21401',
            'id_type'         => 'state_id',
            'id_number'       => 'NEW999',
        ])
        ->assertOk();

    expect(\DB::table('user_account_information')->where('user_id', $user->id)->count())->toBe(1);
    $this->assertDatabaseHas('user_account_information', [
        'user_id' => $user->id,
        'address' => 'New Address',
    ]);
});

it('rejects contact information with a missing required field', function () {
    $user = User::factory()->create(['status' => 'active', 'account_type' => 'individual']);

    $this->actingAs($user, 'sanctum')
        ->putJson('/api/v1/profile/account-information', [
            'address' => '123 Main St',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['date_of_birth', 'country', 'city']);
});

// ── Billing information ───────────────────────────────────────────────────────

it('lets an active user update their billing address', function () {
    $user = User::factory()->create(['status' => 'active', 'account_type' => 'individual']);

    $this->actingAs($user, 'sanctum')
        ->putJson('/api/v1/profile/billing-information', [
            'billing_address'         => '500 Commerce Ave',
            'billing_country'         => 'US',
            'billing_city'            => 'Baltimore',
            'billing_state'           => 'MD',
            'billing_zip_postal_code' => '21202',
        ])
        ->assertOk()
        ->assertJsonPath('data.billing_information.billing_address', '500 Commerce Ave');

    $this->assertDatabaseHas('user_billing_information', [
        'user_id'         => $user->id,
        'billing_address' => '500 Commerce Ave',
    ]);
});

it('does not wipe existing card metadata when editing the billing address', function () {
    $user = User::factory()->create(['status' => 'active', 'account_type' => 'individual']);
    $this->givePaymentMethod($user);

    $this->actingAs($user, 'sanctum')
        ->putJson('/api/v1/profile/billing-information', [
            'billing_address'         => '999 New St',
            'billing_country'         => 'US',
            'billing_city'            => 'Rockville',
            'billing_zip_postal_code' => '20850',
        ])
        ->assertOk();

    // Card metadata must survive an address-only edit.
    $this->assertDatabaseHas('user_billing_information', [
        'user_id'        => $user->id,
        'billing_address'=> '999 New St',
        'card_last_four' => '4242',
        'card_brand'     => 'visa',
    ]);
});

// ── Business information ──────────────────────────────────────────────────────

it('lets a business account update its business information', function () {
    $user = User::factory()->create(['status' => 'active', 'account_type' => 'business']);

    $this->actingAs($user, 'sanctum')
        ->putJson('/api/v1/profile/business-information', [
            'legal_business_name'  => 'Acme Motors LLC',
            'primary_contact_name' => 'Jane Doe',
            'contact_title'        => 'Owner',
            'phone'                => '410-555-0100',
            'address'              => '1 Industrial Way',
            'city'                 => 'Baltimore',
            'state'                => 'MD',
            'zip'                  => '21230',
            'entity_type'          => 'llc',
            'state_of_formation'   => 'MD',
        ])
        ->assertOk()
        ->assertJsonPath('data.business_information.legal_business_name', 'Acme Motors LLC');

    $this->assertDatabaseHas('user_business_information', [
        'user_id'             => $user->id,
        'legal_business_name' => 'Acme Motors LLC',
    ]);
});

it('blocks a non-business account from editing business information', function () {
    $user = User::factory()->create(['status' => 'active', 'account_type' => 'individual']);

    $this->actingAs($user, 'sanctum')
        ->putJson('/api/v1/profile/business-information', [
            'legal_business_name'  => 'Acme Motors LLC',
            'primary_contact_name' => 'Jane Doe',
            'contact_title'        => 'Owner',
            'phone'                => '410-555-0100',
            'address'              => '1 Industrial Way',
            'city'                 => 'Baltimore',
            'state'                => 'MD',
            'zip'                  => '21230',
            'entity_type'          => 'llc',
            'state_of_formation'   => 'MD',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'not_a_business');
});

// ── Dealer information ────────────────────────────────────────────────────────

it('lets a dealer account update its dealer information', function () {
    $user = User::factory()->create(['status' => 'active', 'account_type' => 'dealer']);

    $this->actingAs($user, 'sanctum')
        ->putJson('/api/v1/profile/dealer-information', [
            'company_name'            => 'Best Cars Inc',
            'owner_name'              => 'John Smith',
            'phone'                   => '410-555-0199',
            'primary_contact'         => 'John Smith',
            'license_number'          => 'DL-9988',
            'license_expiration_date' => now()->addYear()->format('Y-m-d'),
            'dealer_address'          => '2 Auto Plaza',
            'dealer_country'          => 'US',
            'dealer_city'             => 'Baltimore',
            'dealer_state'            => 'MD',
            'dealer_zip_code'         => '21224',
        ])
        ->assertOk()
        ->assertJsonPath('data.dealer_information.company_name', 'Best Cars Inc');

    $this->assertDatabaseHas('user_dealer_information', [
        'user_id'      => $user->id,
        'company_name' => 'Best Cars Inc',
    ]);
});

it('blocks a non-dealer account from editing dealer information', function () {
    $user = User::factory()->create(['status' => 'active', 'account_type' => 'individual']);

    $this->actingAs($user, 'sanctum')
        ->putJson('/api/v1/profile/dealer-information', [
            'company_name'            => 'Best Cars Inc',
            'owner_name'              => 'John Smith',
            'phone'                   => '410-555-0199',
            'primary_contact'         => 'John Smith',
            'license_number'          => 'DL-9988',
            'license_expiration_date' => now()->addYear()->format('Y-m-d'),
            'dealer_address'          => '2 Auto Plaza',
            'dealer_country'          => 'US',
            'dealer_city'             => 'Baltimore',
            'dealer_zip_code'         => '21224',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'not_a_dealer');
});

it('rejects a dealer license that is already expired', function () {
    $user = User::factory()->create(['status' => 'active', 'account_type' => 'dealer']);

    $this->actingAs($user, 'sanctum')
        ->putJson('/api/v1/profile/dealer-information', [
            'company_name'            => 'Best Cars Inc',
            'owner_name'              => 'John Smith',
            'phone'                   => '410-555-0199',
            'primary_contact'         => 'John Smith',
            'license_number'          => 'DL-9988',
            'license_expiration_date' => now()->subDay()->format('Y-m-d'),
            'dealer_address'          => '2 Auto Plaza',
            'dealer_country'          => 'US',
            'dealer_city'             => 'Baltimore',
            'dealer_zip_code'         => '21224',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['license_expiration_date']);
});

// ── Auth guard ────────────────────────────────────────────────────────────────

it('requires authentication for profile section edits', function () {
    $this->putJson('/api/v1/profile/billing-information', [])
        ->assertStatus(401);
});
