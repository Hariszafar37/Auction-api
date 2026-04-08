<?php

use App\Models\PowerOfAttorney;
use App\Models\User;
use App\Policies\PowerOfAttorneyPolicy;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->policy = new PowerOfAttorneyPolicy();
});

function makePoaPolicyUser(?string $role = 'buyer'): User
{
    $user = User::factory()->create(['status' => 'active']);
    if ($role) {
        $user->assignRole($role);
    }
    return $user;
}

function makePoaPolicyRow(User $owner): PowerOfAttorney
{
    return PowerOfAttorney::create([
        'user_id'   => $owner->id,
        'type'      => 'upload',
        'status'    => 'signed',
        'file_path' => "poa/{$owner->id}/signed.pdf",
        'disk'      => 'local',
    ]);
}

it('allows the POA owner to view their own POA', function () {
    $owner = makePoaPolicyUser();
    $poa   = makePoaPolicyRow($owner);

    expect($this->policy->view($owner, $poa))->toBeTrue();
});

it('allows an admin to view any POA', function () {
    $admin = makePoaPolicyUser('admin');
    $owner = makePoaPolicyUser();
    $poa   = makePoaPolicyRow($owner);

    expect($this->policy->view($admin, $poa))->toBeTrue();
});

it('denies a different buyer from viewing someone else POA', function () {
    $owner    = makePoaPolicyUser();
    $stranger = makePoaPolicyUser();
    $poa      = makePoaPolicyRow($owner);

    expect($this->policy->view($stranger, $poa))->toBeFalse();
});

it('denies a dealer from viewing an unrelated user POA', function () {
    $owner  = makePoaPolicyUser();
    $dealer = makePoaPolicyUser('dealer');
    $poa    = makePoaPolicyRow($owner);

    expect($this->policy->view($dealer, $poa))->toBeFalse();
});

it('denies a roleless user from viewing a stranger POA', function () {
    $owner = makePoaPolicyUser();
    $noOne = makePoaPolicyUser(null);
    $poa   = makePoaPolicyRow($owner);

    expect($this->policy->view($noOne, $poa))->toBeFalse();
});
