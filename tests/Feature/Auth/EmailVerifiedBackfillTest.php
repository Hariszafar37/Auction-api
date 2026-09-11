<?php

use App\Models\GovProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Covers the repair migration's selection logic.
 *
 * The migration has already run by the time the suite boots, so re-running its
 * statements against purpose-built rows is what actually exercises the WHERE
 * clauses — in particular that it repairs stranded accounts without inventing a
 * verification for an address nobody ever proved.
 */
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
});

function runEmailVerifiedBackfill(): void
{
    $migration = require database_path('migrations/2026_09_11_000001_backfill_missing_email_verified_at.php');
    $migration->up();
}

function makeGovUserWithProfile(string $email, array $userAttrs, array $profileAttrs): User
{
    $user = User::factory()->unverified()->create(array_merge([
        'email'        => $email,
        'account_type' => 'government',
        'password'     => null,
    ], $userAttrs));
    $user->assignRole('buyer');

    GovProfile::create(array_merge([
        'user_id'               => $user->id,
        'entity_name'           => 'Test Entity',
        'entity_subtype'        => 'government',
        'point_of_contact_name' => 'Contact',
        'phone'                 => '555-000-0000',
        'address'               => '1 Plaza',
        'city'                  => 'Annapolis',
        'state'                 => 'MD',
        'zip'                   => '21401',
        'approval_status'       => 'pending',
    ], $profileAttrs));

    return $user;
}

it('backfills a gov account that accepted its invite, using the acceptance time', function () {
    $acceptedAt = now()->subDays(3)->startOfSecond();

    $user = makeGovUserWithProfile('stranded@example.gov',
        ['status' => 'pending_password'],
        ['invite_accepted_at' => $acceptedAt, 'invite_token' => null],
    );

    runEmailVerifiedBackfill();

    $row = DB::table('users')->where('id', $user->id)->first();

    expect($row->email_verified_at)->not->toBeNull()
        // Stamped from the acceptance, not from "now".
        ->and(\Illuminate\Support\Carbon::parse($row->email_verified_at)->timestamp)
        ->toBe($acceptedAt->timestamp);
});

it('leaves a gov account that never accepted its invite untouched', function () {
    // The dangerous case: an admin flipped the account active while the
    // invitation was still outstanding. Nothing proves the address, so the
    // migration must not vouch for it.
    $user = makeGovUserWithProfile('never-accepted@example.gov',
        ['status' => 'active', 'password_set_at' => null],
        ['invite_accepted_at' => null, 'invite_token' => 'still-outstanding'],
    );

    runEmailVerifiedBackfill();

    $row = DB::table('users')->where('id', $user->id)->first();

    expect($row->email_verified_at)->toBeNull();
});

it('backfills an active admin-created account that holds a password', function () {
    $user = User::factory()->unverified()->create([
        'email'           => 'admin.created@example.com',
        'status'          => 'active',
        'password_set_at' => now()->subMonth(),
        'created_at'      => now()->subMonth()->startOfSecond(),
    ]);
    $user->assignRole('staff');

    runEmailVerifiedBackfill();

    $row = DB::table('users')->where('id', $user->id)->first();

    expect($row->email_verified_at)->not->toBeNull();
});

it('leaves a self-registered account still awaiting verification untouched', function () {
    $user = User::factory()->unverified()->create([
        'email'           => 'mid-signup@example.com',
        'status'          => 'pending_email_verification',
        'password_set_at' => null,
    ]);
    $user->assignRole('buyer');

    runEmailVerifiedBackfill();

    $row = DB::table('users')->where('id', $user->id)->first();

    expect($row->email_verified_at)->toBeNull();
});

it('does not move an already-verified timestamp when re-run', function () {
    $verifiedAt = now()->subYear()->startOfSecond();

    $user = makeGovUserWithProfile('already@example.gov',
        ['status' => 'pending_password'],
        ['invite_accepted_at' => now(), 'invite_token' => null],
    );
    DB::table('users')->where('id', $user->id)->update(['email_verified_at' => $verifiedAt]);

    runEmailVerifiedBackfill();
    runEmailVerifiedBackfill(); // idempotent

    $row = DB::table('users')->where('id', $user->id)->first();

    expect(\Illuminate\Support\Carbon::parse($row->email_verified_at)->timestamp)
        ->toBe($verifiedAt->timestamp);
});
