<?php

use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\User;
use App\Models\Vehicle;
use App\Enums\AuctionStatus;
use App\Enums\LotStatus;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Create a user with the prerequisite billing address row already in place,
 * mirroring reality: users always reach the payment-method endpoint AFTER
 * completing the activation billing step.
 */
function makeUserWithBillingAddress(): User
{
    $user = User::factory()->create(['status' => 'active']);

    $user->billingInformation()->create([
        'billing_address'         => '123 Main St',
        'billing_country'         => 'US',
        'billing_city'            => 'Baltimore',
        'billing_zip_postal_code' => '21201',
        'payment_method_added'    => false,
    ]);

    return $user;
}

// ── Payment CRUD ──────────────────────────────────────────────────────────────

it('authenticated user can save a valid payment method (metadata only)', function () {
    $user = makeUserWithBillingAddress();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/users/payment-method', [
            'cardholder_name' => 'Jane Doe',
            'card_number'     => '4242424242424242', // valid Luhn Visa test card
            'expiry_month'    => 12,
            'expiry_year'     => (int) now()->year + 3,
            'cvv'             => '123',
        ]);

    $response->assertStatus(200)
             ->assertJsonPath('success', true);

    $user->refresh()->load('billingInformation');
    expect($user->billingInformation->payment_method_added)->toBeTrue()
        ->and($user->billingInformation->card_brand)->toBe('visa')
        ->and($user->billingInformation->card_last_four)->toBe('4242')
        ->and($user->billingInformation->cardholder_name)->toBe('Jane Doe');

    // Raw number / cvv are NEVER persisted
    expect($user->billingInformation->getAttribute('card_number'))->toBeNull();
    expect($user->billingInformation->getAttribute('cvv'))->toBeNull();
});

it('payment-method store errors if billing address not yet filled', function () {
    // User has NO billing_information row yet (bypass the helper intentionally)
    $user = User::factory()->create(['status' => 'active']);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/users/payment-method', [
            'cardholder_name' => 'Jane Doe',
            'card_number'     => '4242424242424242',
            'expiry_month'    => 12,
            'expiry_year'     => (int) now()->year + 3,
            'cvv'             => '123',
        ]);

    $response->assertStatus(422)
             ->assertJsonPath('code', 'billing_address_required');
});

it('payment method rejects invalid Luhn card number', function () {
    $user = User::factory()->create(['status' => 'active']);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/users/payment-method', [
            'cardholder_name' => 'Jane Doe',
            'card_number'     => '4242424242424243', // bad Luhn
            'expiry_month'    => 12,
            'expiry_year'     => (int) now()->year + 3,
            'cvv'             => '123',
        ]);

    $response->assertStatus(422)
             ->assertJsonValidationErrors(['card_number']);
});

it('payment method rejects expired card in current year', function () {
    $user  = User::factory()->create(['status' => 'active']);
    $now   = now();
    $month = $now->month > 1 ? $now->month - 1 : 1;

    // Skip when running in January — there's no prior month in the same year.
    if ($now->month === 1) {
        $this->markTestSkipped('Cannot construct a same-year past month in January.');
    }

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/users/payment-method', [
            'cardholder_name' => 'Jane Doe',
            'card_number'     => '4242424242424242',
            'expiry_month'    => $month,
            'expiry_year'     => $now->year,
            'cvv'             => '123',
        ]);

    $response->assertStatus(422)
             ->assertJsonValidationErrors(['expiry_month']);
});

it('payment method rejects prior-year expiry', function () {
    $user = makeUserWithBillingAddress();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/users/payment-method', [
            'cardholder_name' => 'Jane Doe',
            'card_number'     => '4242424242424242',
            'expiry_month'    => 6,
            'expiry_year'     => (int) now()->year - 1,
            'cvv'             => '123',
        ]);

    $response->assertStatus(422)
             ->assertJsonValidationErrors(['expiry_year']);
});

it('payment method rejects invalid CVV length', function () {
    $user = makeUserWithBillingAddress();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/users/payment-method', [
            'cardholder_name' => 'Jane Doe',
            'card_number'     => '4242424242424242',
            'expiry_month'    => 12,
            'expiry_year'     => (int) now()->year + 3,
            'cvv'             => '12', // too short
        ]);

    $response->assertStatus(422)
             ->assertJsonValidationErrors(['cvv']);
});

it('payment method requires authentication', function () {
    $response = $this->postJson('/api/v1/users/payment-method', []);

    $response->assertStatus(401);
});

it('GET payment-method returns null when no card on file', function () {
    $user = User::factory()->create(['status' => 'active']);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/users/payment-method');

    $response->assertStatus(200)
             ->assertJsonPath('data.payment_method', null);
});

it('GET payment-method returns metadata when card on file', function () {
    $user = User::factory()->create(['status' => 'active']);
    $this->givePaymentMethod($user);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/users/payment-method');

    $response->assertStatus(200)
             ->assertJsonPath('data.payment_method.card_brand', 'visa')
             ->assertJsonPath('data.payment_method.card_last_four', '4242')
             ->assertJsonPath('data.payment_method.is_valid', true);
});

// ── hasValidPaymentMethod + UserResource flag ────────────────────────────────

it('exposes has_valid_payment_method=false via /auth/me when no card', function () {
    $user = User::factory()->create(['status' => 'active']);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/auth/me');

    $response->assertStatus(200)
             ->assertJsonPath('data.has_valid_payment_method', false);
});

it('exposes has_valid_payment_method=true via /auth/me when valid card exists', function () {
    $user = User::factory()->create(['status' => 'active']);
    $this->givePaymentMethod($user);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/auth/me');

    $response->assertStatus(200)
             ->assertJsonPath('data.has_valid_payment_method', true);
});

// ── Bidding enforcement ───────────────────────────────────────────────────────

function makePaymentTestLot(): array
{
    $seller  = User::factory()->create(['status' => 'active']);
    $creator = User::factory()->create(['status' => 'active']);

    $auction = Auction::create([
        'title'      => 'Payment Gate Test Auction',
        'location'   => 'Baltimore, MD',
        'starts_at'  => now()->subHour(),
        'status'     => AuctionStatus::Live,
        'created_by' => $creator->id,
    ]);

    $vehicle = Vehicle::factory()->create([
        'seller_id' => $seller->id,
        'status'    => 'in_auction',
    ]);

    $lot = AuctionLot::create([
        'auction_id'               => $auction->id,
        'vehicle_id'               => $vehicle->id,
        'lot_number'               => 1,
        'status'                   => LotStatus::Open,
        'starting_bid'             => 1000,
        'dealer_only'              => false,
        'countdown_seconds'        => 30,
        'requires_seller_approval' => false,
    ]);

    return [$auction, $lot];
}

it('bid is blocked with 402 PAYMENT_REQUIRED when user has no card', function () {
    [$auction, $lot] = makePaymentTestLot();

    $buyer = User::factory()->create(['status' => 'active']);

    $response = $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/auctions/{$auction->id}/lots/{$lot->id}/bids", [
            'amount' => 1000,
        ]);

    $response->assertStatus(402)
             ->assertJsonPath('code', 'PAYMENT_REQUIRED')
             ->assertJsonPath('success', false);
});

it('bid is blocked with 402 when card is expired', function () {
    [$auction, $lot] = makePaymentTestLot();

    $buyer = User::factory()->create(['status' => 'active']);
    // Manually seed an EXPIRED card
    $buyer->billingInformation()->updateOrCreate(
        ['user_id' => $buyer->id],
        [
            'billing_address'         => '123 Test St',
            'billing_country'         => 'US',
            'billing_city'            => 'Baltimore',
            'billing_zip_postal_code' => '21201',
            'payment_method_added'    => true,
            'cardholder_name'         => 'Test User',
            'card_brand'              => 'visa',
            'card_last_four'          => '4242',
            'card_expiry_month'       => 6,
            'card_expiry_year'        => (int) now()->year - 1,
        ]
    );

    $response = $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/auctions/{$auction->id}/lots/{$lot->id}/bids", [
            'amount' => 1000,
        ]);

    $response->assertStatus(402)
             ->assertJsonPath('code', 'PAYMENT_REQUIRED');
});

it('bid succeeds with a valid payment method', function () {
    [$auction, $lot] = makePaymentTestLot();

    $buyer = User::factory()->create(['status' => 'active']);
    $this->givePaymentMethod($buyer);

    $response = $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/auctions/{$auction->id}/lots/{$lot->id}/bids", [
            'amount' => 1000,
        ]);

    $response->assertStatus(200);
});

it('proxy bid is blocked with 402 when user has no card', function () {
    [$auction, $lot] = makePaymentTestLot();

    $buyer = User::factory()->create(['status' => 'active']);

    $response = $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/auctions/{$auction->id}/lots/{$lot->id}/proxy-bid", [
            'max_amount' => 2000,
        ]);

    $response->assertStatus(402)
             ->assertJsonPath('code', 'PAYMENT_REQUIRED');
});

it('proxy bid succeeds with a valid payment method', function () {
    [$auction, $lot] = makePaymentTestLot();

    $buyer = User::factory()->create(['status' => 'active']);
    $this->givePaymentMethod($buyer);

    $response = $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/auctions/{$auction->id}/lots/{$lot->id}/proxy-bid", [
            'max_amount' => 2000,
        ]);

    $response->assertStatus(200);
});

// ── Activation still completes WITHOUT a card on file ────────────────────────

it('individual user can complete activation without a payment method', function () {
    $user = User::factory()->create([
        'status'            => 'pending_activation',
        'account_type'      => 'individual',
        'account_intent'    => 'buyer',
        'email_verified_at' => now(),
        'password_set_at'   => now(),
    ]);
    $user->assignRole('buyer');

    $user->accountInformation()->create([
        'date_of_birth'    => '1990-01-01',
        'address'          => '123 Main St',
        'country'          => 'US',
        'state'            => 'MD',
        'city'             => 'Baltimore',
        'zip_postal_code'  => '21201',
        'id_type'          => 'driver_license',
        'id_number'        => 'D12345678',
        'id_issuing_state' => 'MD',
        'id_expiry'        => now()->addYears(5)->toDateString(),
    ]);

    // Billing ADDRESS only — no card, payment_method_added stays false
    $user->billingInformation()->create([
        'billing_address'         => '123 Main St',
        'billing_country'         => 'US',
        'billing_city'            => 'Baltimore',
        'billing_zip_postal_code' => '21201',
        'payment_method_added'    => false,
    ]);

    \App\Models\UserDocument::create([
        'user_id'       => $user->id,
        'type'          => 'id',
        'file_path'     => 'user-documents/test/id/test.pdf',
        'disk'          => 'public',
        'original_name' => 'test.pdf',
        'mime_type'     => 'application/pdf',
        'size_bytes'    => 1024,
    ]);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/activation/complete');

    $response->assertStatus(200)
             ->assertJsonPath('success', true);

    $user->refresh();
    expect($user->status)->toBe('active');
    // Confirms payment was not required for activation
    expect($user->billingInformation->payment_method_added)->toBeFalse();
    expect($user->hasValidPaymentMethod())->toBeFalse();
});
