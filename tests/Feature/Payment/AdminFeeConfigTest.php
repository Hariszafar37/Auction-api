<?php

use App\Models\FeeConfiguration;
use Database\Seeders\RolePermissionSeeder;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

test('admin can create an online platform fee configuration', function () {
    // Regression: 'online_platform_fee' was missing from the allowed fee_type list
    // in StoreFeeConfigurationRequest, so the admin UI could never create/manage it
    // even though the column, calculation service, and resource all support it.
    $this->actingAsAdmin()
        ->postJson('/api/v1/admin/fees', [
            'fee_type'         => 'online_platform_fee',
            'label'            => 'Online Platform Fee',
            'calculation_type' => 'flat',
            'amount'           => 50,
            'applies_to'       => 'buyer',
        ])
        ->assertCreated();

    expect(FeeConfiguration::where('fee_type', 'online_platform_fee')->exists())->toBeTrue();
});

test('store fee config still rejects an unknown fee type', function () {
    $this->actingAsAdmin()
        ->postJson('/api/v1/admin/fees', [
            'fee_type'         => 'totally_made_up_fee',
            'label'            => 'Nope',
            'calculation_type' => 'flat',
            'amount'           => 10,
        ])
        ->assertStatus(422);
});
