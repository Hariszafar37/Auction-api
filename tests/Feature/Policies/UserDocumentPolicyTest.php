<?php

use App\Models\User;
use App\Models\UserDocument;
use App\Policies\UserDocumentPolicy;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->policy = new UserDocumentPolicy();
});

function makeDocPolicyUser(?string $role = 'buyer'): User
{
    $user = User::factory()->create(['status' => 'active']);
    if ($role) {
        $user->assignRole($role);
    }
    return $user;
}

function makeDocPolicyDoc(User $owner): UserDocument
{
    return UserDocument::create([
        'user_id'       => $owner->id,
        'type'          => 'id',
        'file_path'     => "user-documents/{$owner->id}/id/x.png",
        'disk'          => 'local',
        'original_name' => 'x.png',
        'mime_type'     => 'image/png',
        'size_bytes'    => 10,
        'status'        => 'pending_review',
    ]);
}

it('allows the document owner to view their own document', function () {
    $owner = makeDocPolicyUser();
    $doc   = makeDocPolicyDoc($owner);

    expect($this->policy->view($owner, $doc))->toBeTrue();
});

it('allows an admin to view any user document', function () {
    $admin = makeDocPolicyUser('admin');
    $owner = makeDocPolicyUser();
    $doc   = makeDocPolicyDoc($owner);

    expect($this->policy->view($admin, $doc))->toBeTrue();
});

it('denies a different buyer from viewing someone else document', function () {
    $owner    = makeDocPolicyUser();
    $stranger = makeDocPolicyUser();
    $doc      = makeDocPolicyDoc($owner);

    expect($this->policy->view($stranger, $doc))->toBeFalse();
});

it('denies a dealer from viewing an unrelated buyer document', function () {
    $owner  = makeDocPolicyUser();
    $dealer = makeDocPolicyUser('dealer');
    $doc    = makeDocPolicyDoc($owner);

    expect($this->policy->view($dealer, $doc))->toBeFalse();
});

it('denies a user with no roles from viewing a stranger document', function () {
    $owner  = makeDocPolicyUser();
    $noOne  = makeDocPolicyUser(null);
    $doc    = makeDocPolicyDoc($owner);

    expect($this->policy->view($noOne, $doc))->toBeFalse();
});
