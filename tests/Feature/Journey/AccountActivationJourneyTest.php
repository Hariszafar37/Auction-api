<?php

use App\Models\GovProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * End-to-end account journeys, walked through the real HTTP endpoints.
 *
 * The existing suites cover each step in isolation, but they all start from
 * `User::factory()`, whose default state sets `email_verified_at => now()`.
 * Factories bypass `$fillable`, so those fixtures are verified before the flow
 * begins — which is precisely how a mass-assignment bug in acceptInvite() stayed
 * invisible behind a green suite while every real government signup was broken.
 *
 * These tests therefore create accounts ONLY through the endpoints an admin or a
 * visitor actually calls, and assert against stored rows rather than in-memory
 * models. No step is simulated by writing the database directly.
 */
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    Storage::fake('public');
});

// ── Helpers ──────────────────────────────────────────────────────────────────

function journeyAdmin(): User
{
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    return $admin;
}

/** The signed link Laravel mails out, rebuilt exactly as the notification does. */
function journeyVerificationUrl(User $user): string
{
    return URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())]
    );
}

function journeyRegister(string $email, string $first = 'Journey', string $last = 'Tester'): User
{
    test()->postJson('/api/v1/auth/register', [
        'email'                    => $email,
        'email_confirmation'       => $email,
        'first_name'               => $first,
        'last_name'                => $last,
        'primary_phone'            => '555-123-4567',
        'agree_terms'              => true,
        'agree_ecomm_consent'      => true,
        'agree_accuracy_confirmed' => true,
    ] + botGuardFields())->assertStatus(201);

    return User::where('email', $email)->firstOrFail();
}

/** Fills the shared wizard steps via the real endpoints. */
function journeyFillCommonSteps(User $user): void
{
    test()->actingAs($user, 'sanctum')
        ->postJson('/api/v1/activation/account-information', [
            'date_of_birth'    => '1990-05-10',
            'address'          => '100 Journey St',
            'country'          => 'US',
            'state'            => 'Texas',
            'city'             => 'Austin',
            'zip_postal_code'  => '73301',
            'id_type'          => 'driver_license',
            'id_number'        => 'TX-DL-JOURNEY',
            'id_issuing_state' => 'Texas',
            'id_expiry'        => now()->addYears(2)->format('Y-m-d'),
        ])->assertOk();

    test()->actingAs($user, 'sanctum')
        ->postJson('/api/v1/activation/billing-information', [
            'billing_address'         => '200 Billing Rd',
            'billing_country'         => 'US',
            'billing_city'            => 'Austin',
            'billing_state'           => 'Texas',
            'billing_zip_postal_code' => '73301',
        ])->assertOk();

    test()->actingAs($user, 'sanctum')
        ->postJson('/api/v1/activation/upload-documents', [
            'document_type' => 'id',
            'file'          => UploadedFile::fake()->image('id-front.jpg'),
        ])->assertStatus(201);
}

// ── Journey 1: self-registration → individual buyer → active ─────────────────

it('walks a self-registered individual from register to active', function () {
    // Step 1 — register. No password, not verified.
    $user = journeyRegister('individual.journey@example.com');

    expect($user->status)->toBe('pending_email_verification')
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->password)->toBeNull();

    // Logging in this early must fail on verification, not on credentials.
    $this->postJson('/api/v1/auth/login', [
        'email'    => 'individual.journey@example.com',
        'password' => 'Whatever123!',
    ])->assertStatus(422); // no password set yet → credential mismatch

    // Step 2 — click the emailed verification link.
    $this->get(journeyVerificationUrl($user))->assertRedirect();

    $row = DB::table('users')->where('id', $user->id)->first();
    expect($row->email_verified_at)->not->toBeNull()
        ->and($row->status)->toBe('pending_password');

    // Step 3 — set password.
    $this->postJson('/api/v1/auth/set-password', [
        'email'                 => 'individual.journey@example.com',
        'password'              => 'SecurePass123!',
        'password_confirmation' => 'SecurePass123!',
    ])->assertOk();

    // Step 4 — log in; the wizard is required and points at step one.
    $this->postJson('/api/v1/auth/login', [
        'email'    => 'individual.journey@example.com',
        'password' => 'SecurePass123!',
    ])->assertOk()
      ->assertJsonPath('data.activation_required', true)
      ->assertJsonPath('data.next_activation_url', '/activation/account-type');

    $user->refresh();

    // Step 5 — walk the wizard.
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/activation/account-type', [
            'account_type'   => 'individual',
            'account_intent' => 'buyer',
        ])->assertOk();

    journeyFillCommonSteps($user);

    // Step 6 — complete. Individuals need no admin approval.
    $this->actingAs($user->fresh(), 'sanctum')
        ->postJson('/api/v1/activation/complete')
        ->assertOk()
        ->assertJsonPath('data.activation_status', 'complete');

    expect($user->fresh()->status)->toBe('active');

    // Step 7 — log in again; no activation redirect remains.
    $this->postJson('/api/v1/auth/login', [
        'email'    => 'individual.journey@example.com',
        'password' => 'SecurePass123!',
    ])->assertOk()
      ->assertJsonPath('data.activation_required', false)
      ->assertJsonPath('data.next_activation_url', null);
});

// ── Journey 2: self-registration → dealer → admin approval → active ──────────

it('walks a self-registered dealer through approval to active', function () {
    $user = journeyRegister('dealer.journey@example.com', 'Dealer', 'Journey');

    $this->get(journeyVerificationUrl($user))->assertRedirect();

    $this->postJson('/api/v1/auth/set-password', [
        'email'                 => 'dealer.journey@example.com',
        'password'              => 'SecurePass123!',
        'password_confirmation' => 'SecurePass123!',
    ])->assertOk();

    $user->refresh();

    // Choosing "dealer" swaps the role and forces buyer_and_seller intent.
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/activation/account-type', ['account_type' => 'dealer'])
        ->assertOk();

    $user->refresh();
    expect($user->hasRole('dealer'))->toBeTrue()
        ->and($user->account_intent)->toBe('buyer_and_seller');

    journeyFillCommonSteps($user);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/activation/dealer-information', [
            'company_name'            => 'Journey Motors',
            'owner_name'              => 'Dealer Journey',
            'phone'                   => '555-999-8888',
            'primary_contact'         => 'owner@journeymotors.test',
            'license_number'          => 'DLR-JOURNEY-1',
            'license_expiration_date' => now()->addYears(3)->format('Y-m-d'),
            'dealer_address'          => '789 Lot Ave',
            'dealer_country'          => 'US',
            'dealer_city'             => 'Houston',
            'dealer_state'            => 'TX',
            'dealer_zip_code'         => '77001',
            'dealer_classification'   => 'maryland_wholesale',
        ])->assertOk();

    // buyer_and_seller intent gates completion behind a signed POA.
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/activation/complete')
        ->assertStatus(422)
        ->assertJsonPath('code', 'activation_incomplete');

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/activation/poa/esign', ['signer_printed_name' => 'Dealer Journey'])
        ->assertStatus(201);

    $this->actingAs($user->fresh(), 'sanctum')
        ->postJson('/api/v1/activation/complete')
        ->assertOk()
        ->assertJsonPath('data.activation_status', 'pending_approval');

    expect($user->fresh()->status)->toBe('pending_activation');

    // Awaiting approval must route to the holding page, never back into the wizard.
    //
    // Note the deliberate asymmetry with the government journey below:
    // `isActivationRequired()` short-circuits to false only for `government`
    // accounts, which never touch the wizard. A dealer is not active yet, so the
    // flag stays true — what keeps them out of a redirect loop is
    // `next_activation_url`, derived from getActivationStatus() === 'pending_approval'.
    $this->postJson('/api/v1/auth/login', [
        'email'    => 'dealer.journey@example.com',
        'password' => 'SecurePass123!',
    ])->assertOk()
      ->assertJsonPath('data.activation_required', true)
      ->assertJsonPath('data.next_activation_url', '/activation/pending-approval');

    // Admin approves.
    $this->actingAs(journeyAdmin(), 'sanctum')
        ->postJson("/api/v1/admin/dealers/{$user->id}/approve")
        ->assertOk();

    expect($user->fresh()->status)->toBe('active');

    $this->postJson('/api/v1/auth/login', [
        'email'    => 'dealer.journey@example.com',
        'password' => 'SecurePass123!',
    ])->assertOk()
      ->assertJsonPath('data.next_activation_url', null);
});

// ── Journey 3: admin-created government → invite → set-password → approve ────

it('walks an admin-created government account from invite to active', function () {
    $admin = journeyAdmin();

    // Step 1 — admin keys in the account. This is the path that was broken.
    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/admin/government', [
            'entity_name'           => 'Department of Journeys',
            'entity_subtype'        => 'government',
            'point_of_contact_name' => 'Gov Journey',
            'email'                 => 'gov.journey@example.gov',
            'phone'                 => '555-000-1111',
            'address'               => '1 Gov Plaza',
            'city'                  => 'Annapolis',
            'state'                 => 'MD',
            'zip'                   => '21401',
        ])->assertStatus(201);

    $user = User::where('email', 'gov.journey@example.gov')->firstOrFail();

    // The account starts unverified and password-less — this is what the factory
    // default used to hide.
    expect($user->email_verified_at)->toBeNull()
        ->and($user->password)->toBeNull()
        ->and($user->account_type)->toBe('government');

    $token = GovProfile::where('user_id', $user->id)->value('invite_token');
    expect($token)->not->toBeNull();

    // Step 2 — the invitee opens the emailed link.
    $this->getJson("/api/v1/auth/accept-invite?token={$token}")
        ->assertOk()
        ->assertJsonPath('data.email', 'gov.journey@example.gov')
        ->assertJsonPath('data.entity_name', 'Department of Journeys');

    // Step 3 — accepts the consents.
    $this->postJson('/api/v1/auth/accept-invite', [
        'token'                    => $token,
        'agree_ecomm_consent'      => true,
        'agree_accuracy_confirmed' => true,
    ])->assertOk();

    // The regression: read the stored row, not the model. `email_verified_at` was
    // being dropped here while the fillable `status` key saved, leaving an
    // account that could never set a password.
    $row = DB::table('users')->where('id', $user->id)->first();
    expect($row->email_verified_at)->not->toBeNull()
        ->and($row->status)->toBe('pending_password');

    // Step 4 — set password. Previously 422 `email_not_verified`.
    $this->postJson('/api/v1/auth/set-password', [
        'email'                 => 'gov.journey@example.gov',
        'password'              => 'SecurePass123!',
        'password_confirmation' => 'SecurePass123!',
    ])->assertOk();

    // Step 5 — gov accounts skip the wizard and wait on admin approval.
    $this->postJson('/api/v1/auth/login', [
        'email'    => 'gov.journey@example.gov',
        'password' => 'SecurePass123!',
    ])->assertOk()
      ->assertJsonPath('data.activation_required', false)
      ->assertJsonPath('data.next_activation_url', '/activation/pending-approval');

    // Step 6 — admin approves.
    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/government/{$user->id}/approve")
        ->assertOk();

    expect($user->fresh()->status)->toBe('active');

    $this->postJson('/api/v1/auth/login', [
        'email'    => 'gov.journey@example.gov',
        'password' => 'SecurePass123!',
    ])->assertOk()
      ->assertJsonPath('data.activation_required', false)
      ->assertJsonPath('data.next_activation_url', null);
});

it('keeps a government invite usable when the holder pauses before setting a password', function () {
    $admin = journeyAdmin();

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/admin/government', [
            'entity_name'           => 'Department of Pauses',
            'entity_subtype'        => 'charity',
            'point_of_contact_name' => 'Pause Journey',
            'email'                 => 'pause.journey@example.gov',
            'phone'                 => '555-222-3333',
            'address'               => '2 Gov Plaza',
            'city'                  => 'Annapolis',
            'state'                 => 'MD',
            'zip'                   => '21401',
        ])->assertStatus(201);

    $user  = User::where('email', 'pause.journey@example.gov')->firstOrFail();
    $token = GovProfile::where('user_id', $user->id)->value('invite_token');

    $this->postJson('/api/v1/auth/accept-invite', [
        'token'                    => $token,
        'agree_ecomm_consent'      => true,
        'agree_accuracy_confirmed' => true,
    ])->assertOk();

    // The holder closes the tab here. Acceptance is one-time, so the link is
    // spent — but the account must still be able to finish, because verification
    // is now genuinely recorded.
    $this->postJson('/api/v1/auth/accept-invite', [
        'token'                    => $token,
        'agree_ecomm_consent'      => true,
        'agree_accuracy_confirmed' => true,
    ])->assertStatus(404);

    $this->postJson('/api/v1/auth/set-password', [
        'email'                 => 'pause.journey@example.gov',
        'password'              => 'SecurePass123!',
        'password_confirmation' => 'SecurePass123!',
    ])->assertOk();

    expect($user->fresh()->status)->toBe('pending_activation');
});

// ── Journey 4: admin-created staff account ───────────────────────────────────

it('lets an admin-created staff user log in immediately', function () {
    $this->actingAs(journeyAdmin(), 'sanctum')
        ->postJson('/api/v1/admin/users', [
            'name'                  => 'Staff Journey',
            'email'                 => 'staff.journey@example.com',
            'password'              => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role'                  => 'staff',
        ])->assertStatus(201);

    // Verified at the row level: `email_verified_at` is not fillable, so a
    // regression here is silent.
    $row = DB::table('users')->where('email', 'staff.journey@example.com')->first();
    expect($row->email_verified_at)->not->toBeNull()
        ->and($row->status)->toBe('active');

    $this->postJson('/api/v1/auth/login', [
        'email'    => 'staff.journey@example.com',
        'password' => 'SecurePass123!',
    ])->assertOk()
      ->assertJsonPath('data.activation_required', false)
      ->assertJsonPath('data.next_activation_url', null);

    // An active, verified account must also clear the activation pre-conditions
    // that read hasVerifiedEmail().
    $staff = User::where('email', 'staff.journey@example.com')->firstOrFail();
    expect($staff->hasVerifiedEmail())->toBeTrue();
});
