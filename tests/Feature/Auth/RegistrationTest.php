<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
});

it('registers a new user and returns next_step=verify_email', function () {
    Event::fake([Registered::class]);

    $response = $this->postJson('/api/v1/auth/register', [
        'email'                    => 'user@example.com',
        'email_confirmation'       => 'user@example.com',
        'first_name'               => 'Jane',
        'last_name'                => 'Doe',
        'primary_phone'            => '555-123-4567',
        'consent_marketing'        => true,
        'agree_terms'              => true,
        'agree_bidder_terms'       => true,
        'agree_ecomm_consent'      => true,
        'agree_accuracy_confirmed' => true,
    ]);

    $response->assertStatus(201)
        ->assertJson(['success' => true])
        ->assertJsonPath('data.next_step', 'verify_email')
        ->assertJsonStructure(['data' => ['user_id', 'next_step']]);

    $this->assertDatabaseHas('users', [
        'email'      => 'user@example.com',
        'first_name' => 'Jane',
        'last_name'  => 'Doe',
        'name'       => 'Jane Doe',
        'status'     => 'pending_email_verification',
    ]);

    Event::assertDispatched(Registered::class);
});

it('user has no password after registration', function () {
    $this->postJson('/api/v1/auth/register', [
        'email'                    => 'nopass@example.com',
        'email_confirmation'       => 'nopass@example.com',
        'first_name'               => 'No',
        'last_name'                => 'Pass',
        'primary_phone'            => '555-000-0000',
        'agree_terms'              => true,
        'agree_bidder_terms'       => true,
        'agree_ecomm_consent'      => true,
        'agree_accuracy_confirmed' => true,
    ]);

    $user = User::where('email', 'nopass@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->password)->toBeNull();
});

it('assigns the buyer role on registration', function () {
    $this->postJson('/api/v1/auth/register', [
        'email'                    => 'buyer@example.com',
        'email_confirmation'       => 'buyer@example.com',
        'first_name'               => 'Buyer',
        'last_name'                => 'Test',
        'primary_phone'            => '555-111-2222',
        'agree_terms'              => true,
        'agree_bidder_terms'       => true,
        'agree_ecomm_consent'      => true,
        'agree_accuracy_confirmed' => true,
    ]);

    $user = User::where('email', 'buyer@example.com')->first();
    expect($user->hasRole('buyer'))->toBeTrue();
});

it('rejects registration when agree_terms is false', function () {
    $this->postJson('/api/v1/auth/register', [
        'email'              => 'user@example.com',
        'email_confirmation' => 'user@example.com',
        'first_name'         => 'Jane',
        'last_name'          => 'Doe',
        'primary_phone'      => '555-000-0000',
        'agree_terms'        => false,
    ])->assertStatus(422)
      ->assertJsonPath('errors.agree_terms', fn ($v) => count($v) > 0);
});

it('rejects registration when emails do not match', function () {
    $this->postJson('/api/v1/auth/register', [
        'email'              => 'user@example.com',
        'email_confirmation' => 'other@example.com',
        'first_name'         => 'Jane',
        'last_name'          => 'Doe',
        'primary_phone'      => '555-000-0000',
        'agree_terms'        => true,
    ])->assertStatus(422)
      ->assertJsonPath('errors.email_confirmation', fn ($v) => count($v) > 0);
});

it('rejects duplicate email', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->postJson('/api/v1/auth/register', [
        'email'              => 'taken@example.com',
        'email_confirmation' => 'taken@example.com',
        'first_name'         => 'Jane',
        'last_name'          => 'Doe',
        'primary_phone'      => '555-000-0000',
        'agree_terms'        => true,
    ])->assertStatus(422)
      ->assertJsonPath('errors.email', fn ($v) => count($v) > 0);
});

it('rejects registration with missing required fields', function () {
    $this->postJson('/api/v1/auth/register', [])
        ->assertStatus(422)
        ->assertJsonPath('errors.email', fn ($v) => count($v) > 0)
        ->assertJsonPath('errors.first_name', fn ($v) => count($v) > 0)
        ->assertJsonPath('errors.primary_phone', fn ($v) => count($v) > 0)
        ->assertJsonPath('errors.agree_terms', fn ($v) => count($v) > 0);
});

// ── Compliance fields ─────────────────────────────────────────────────────────

it('stores middle_name when provided', function () {
    Event::fake([\Illuminate\Auth\Events\Registered::class]);

    $this->postJson('/api/v1/auth/register', [
        'email'                    => 'mn@example.com',
        'email_confirmation'       => 'mn@example.com',
        'first_name'               => 'John',
        'middle_name'              => 'Michael',
        'last_name'                => 'Doe',
        'primary_phone'            => '555-000-0001',
        'agree_terms'              => true,
        'agree_bidder_terms'       => true,
        'agree_ecomm_consent'      => true,
        'agree_accuracy_confirmed' => true,
    ])->assertStatus(201);

    $this->assertDatabaseHas('users', [
        'email'       => 'mn@example.com',
        'middle_name' => 'Michael',
    ]);
});

it('rejects registration when agree_bidder_terms is false', function () {
    $this->postJson('/api/v1/auth/register', [
        'email'                    => 'test@example.com',
        'email_confirmation'       => 'test@example.com',
        'first_name'               => 'Jane',
        'last_name'                => 'Doe',
        'primary_phone'            => '555-000-0001',
        'agree_terms'              => true,
        'agree_bidder_terms'       => false,
        'agree_ecomm_consent'      => true,
        'agree_accuracy_confirmed' => true,
    ])->assertStatus(422)
      ->assertJsonPath('errors.agree_bidder_terms', fn ($v) => count($v) > 0);
});

it('rejects registration when agree_ecomm_consent is false', function () {
    $this->postJson('/api/v1/auth/register', [
        'email'                    => 'test2@example.com',
        'email_confirmation'       => 'test2@example.com',
        'first_name'               => 'Jane',
        'last_name'                => 'Doe',
        'primary_phone'            => '555-000-0002',
        'agree_terms'              => true,
        'agree_bidder_terms'       => true,
        'agree_ecomm_consent'      => false,
        'agree_accuracy_confirmed' => true,
    ])->assertStatus(422)
      ->assertJsonPath('errors.agree_ecomm_consent', fn ($v) => count($v) > 0);
});

it('rejects registration when agree_accuracy_confirmed is false', function () {
    $this->postJson('/api/v1/auth/register', [
        'email'                    => 'test3@example.com',
        'email_confirmation'       => 'test3@example.com',
        'first_name'               => 'Jane',
        'last_name'                => 'Doe',
        'primary_phone'            => '555-000-0003',
        'agree_terms'              => true,
        'agree_bidder_terms'       => true,
        'agree_ecomm_consent'      => true,
        'agree_accuracy_confirmed' => false,
    ])->assertStatus(422)
      ->assertJsonPath('errors.agree_accuracy_confirmed', fn ($v) => count($v) > 0);
});

it('stores terms_version on registration', function () {
    Event::fake([\Illuminate\Auth\Events\Registered::class]);

    $this->postJson('/api/v1/auth/register', [
        'email'                    => 'tv@example.com',
        'email_confirmation'       => 'tv@example.com',
        'first_name'               => 'Terms',
        'last_name'                => 'Version',
        'primary_phone'            => '555-000-0004',
        'agree_terms'              => true,
        'agree_bidder_terms'       => true,
        'agree_ecomm_consent'      => true,
        'agree_accuracy_confirmed' => true,
    ])->assertStatus(201);

    $user = User::where('email', 'tv@example.com')->first();
    expect($user->terms_version)->not->toBeNull()->toBeString();
});

it('stores registration_ip_address on registration', function () {
    Event::fake([\Illuminate\Auth\Events\Registered::class]);

    $this->postJson('/api/v1/auth/register', [
        'email'                    => 'ip@example.com',
        'email_confirmation'       => 'ip@example.com',
        'first_name'               => 'IP',
        'last_name'                => 'Test',
        'primary_phone'            => '555-000-0005',
        'agree_terms'              => true,
        'agree_bidder_terms'       => true,
        'agree_ecomm_consent'      => true,
        'agree_accuracy_confirmed' => true,
    ])->assertStatus(201);

    $user = User::where('email', 'ip@example.com')->first();
    // IP is set from the request; in tests this is 127.0.0.1
    expect($user->registration_ip_address)->not->toBeNull();
});
