<?php

use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use App\Models\UserBillingInformation;
use App\Services\Payment\StripeService;
use Database\Seeders\RolePermissionSeeder;
use Stripe\PaymentIntent;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class, \Tests\Helpers\CreatesInvoiceData::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    config(['services.stripe.secret' => 'sk_test_fake_for_tests']);
});

// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Give a buyer a usable card on file: a Stripe customer plus billing information
 * carrying a reusable PaymentMethod that has not expired.
 */
function giveSavedCard(User $buyer, array $overrides = []): UserBillingInformation
{
    $buyer->forceFill(['stripe_customer_id' => 'cus_test_' . $buyer->id])->save();

    return UserBillingInformation::create(array_merge([
        'user_id'                  => $buyer->id,
        'billing_address'          => '1 Test Street',
        'billing_country'          => 'US',
        'billing_city'             => 'Baltimore',
        'billing_state'            => 'MD',
        'billing_zip_postal_code'  => '21201',
        'payment_method_added'     => true,
        'stripe_payment_method_id' => 'pm_test_saved_card',
        'cardholder_name'          => 'Test Buyer',
        'card_brand'               => 'visa',
        'card_last_four'           => '4242',
        'card_expiry_month'        => 12,
        'card_expiry_year'         => (int) now()->addYears(3)->format('Y'),
    ], $overrides));
}

/**
 * Swap StripeService for a fake that records what it was asked to create and
 * hands back a deterministic PaymentIntent. Keeps the suite offline, which is
 * exactly what that service's container binding exists for.
 *
 * Returns an ArrayObject the test can inspect: each entry is one create call.
 */
function fakeStripe(): ArrayObject
{
    $calls = new ArrayObject();

    $fake = new class($calls) extends StripeService {
        public function __construct(private ArrayObject $calls) {}

        public function configured(): bool
        {
            return true;
        }

        public function createUnconfirmedPaymentIntent(
            int $amountCents,
            array $metadata,
            string $idempotencyKey,
            ?string $description = null,
            ?string $customerId = null,
            ?string $paymentMethodId = null,
        ): PaymentIntent {
            $this->calls[] = compact(
                'amountCents', 'metadata', 'idempotencyKey',
                'description', 'customerId', 'paymentMethodId',
            );

            // Distinct id per idempotency key, mirroring Stripe's own behaviour:
            // the same key replays the same intent, a different key makes a new one.
            $id = 'pi_' . substr(md5($idempotencyKey), 0, 20);

            return PaymentIntent::constructFrom([
                'id'            => $id,
                'client_secret' => $id . '_secret',
                'amount'        => $amountCents,
                'status'        => $customerId ? 'requires_confirmation' : 'requires_payment_method',
            ]);
        }
    };

    app()->instance(StripeService::class, $fake);

    return $calls;
}

// ─── Card on file ─────────────────────────────────────────────────────────────

test('use_saved_card attaches the buyer customer and payment method to the intent', function () {
    $calls   = fakeStripe();
    $buyer   = User::factory()->create(['status' => 'active']);
    giveSavedCard($buyer);
    $invoice = $this->makeInvoice($buyer);

    $this->actingAs($buyer, 'sanctum')
         ->postJson("/api/v1/my/invoices/{$invoice->id}/payment-intent", ['use_saved_card' => true])
         ->assertStatus(201)
         ->assertJsonPath('data.invoice_id', $invoice->id);

    expect($calls)->toHaveCount(1);
    expect($calls[0]['customerId'])->toBe('cus_test_' . $buyer->id)
        ->and($calls[0]['paymentMethodId'])->toBe('pm_test_saved_card')
        ->and($calls[0]['amountCents'])->toBe(580000)
        ->and($calls[0]['metadata']['card_source'])->toBe('saved');

    expect(InvoicePayment::where('invoice_id', $invoice->id)->first()->card_source)->toBe('saved');
});

test('a new-card intent attaches no customer or payment method', function () {
    $calls   = fakeStripe();
    $buyer   = User::factory()->create(['status' => 'active']);
    giveSavedCard($buyer); // present, but not requested
    $invoice = $this->makeInvoice($buyer);

    $this->actingAs($buyer, 'sanctum')
         ->postJson("/api/v1/my/invoices/{$invoice->id}/payment-intent")
         ->assertStatus(201);

    expect($calls[0]['customerId'])->toBeNull()
        ->and($calls[0]['paymentMethodId'])->toBeNull()
        ->and($calls[0]['metadata']['card_source'])->toBe('new');

    expect(InvoicePayment::where('invoice_id', $invoice->id)->first()->card_source)->toBe('new');
});

test('use_saved_card returns 422 no_saved_card when the buyer has no card', function () {
    $calls   = fakeStripe();
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    $this->actingAs($buyer, 'sanctum')
         ->postJson("/api/v1/my/invoices/{$invoice->id}/payment-intent", ['use_saved_card' => true])
         ->assertStatus(422)
         ->assertJsonPath('code', 'no_saved_card');

    // The guard must run before Stripe and before any ledger row is written,
    // otherwise a buyer with no card would leave a phantom pending payment.
    expect($calls)->toHaveCount(0);
    expect(InvoicePayment::where('invoice_id', $invoice->id)->count())->toBe(0);
});

test('use_saved_card returns 422 when the card on file has expired', function () {
    $calls   = fakeStripe();
    $buyer   = User::factory()->create(['status' => 'active']);
    giveSavedCard($buyer, [
        'card_expiry_month' => 1,
        'card_expiry_year'  => (int) now()->subYear()->format('Y'),
    ]);
    $invoice = $this->makeInvoice($buyer);

    $this->actingAs($buyer, 'sanctum')
         ->postJson("/api/v1/my/invoices/{$invoice->id}/payment-intent", ['use_saved_card' => true])
         ->assertStatus(422)
         ->assertJsonPath('code', 'no_saved_card');

    expect($calls)->toHaveCount(0);
});

test('use_saved_card returns 422 when the buyer has no stripe customer', function () {
    $calls   = fakeStripe();
    $buyer   = User::factory()->create(['status' => 'active']);
    giveSavedCard($buyer);
    $buyer->forceFill(['stripe_customer_id' => null])->save();
    $invoice = $this->makeInvoice($buyer);

    $this->actingAs($buyer, 'sanctum')
         ->postJson("/api/v1/my/invoices/{$invoice->id}/payment-intent", ['use_saved_card' => true])
         ->assertStatus(422)
         ->assertJsonPath('code', 'no_saved_card');

    expect($calls)->toHaveCount(0);
});

test('an admin paying a buyer invoice attaches the buyers card, never their own', function () {
    $calls = fakeStripe();

    $buyer = User::factory()->create(['status' => 'active']);
    giveSavedCard($buyer);

    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');
    giveSavedCard($admin, ['stripe_payment_method_id' => 'pm_test_ADMIN_card']);

    $invoice = $this->makeInvoice($buyer);

    $this->actingAs($admin, 'sanctum')
         ->postJson("/api/v1/my/invoices/{$invoice->id}/payment-intent", ['use_saved_card' => true])
         ->assertStatus(201);

    expect($calls[0]['customerId'])->toBe('cus_test_' . $buyer->id)
        ->and($calls[0]['paymentMethodId'])->toBe('pm_test_saved_card');
});

// ─── Idempotency-key separation (the load-bearing regression) ─────────────────

test('saved-card and new-card attempts for the same amount use different idempotency keys', function () {
    $calls   = fakeStripe();
    $buyer   = User::factory()->create(['status' => 'active']);
    giveSavedCard($buyer);
    $invoice = $this->makeInvoice($buyer);

    $this->actingAs($buyer, 'sanctum')
         ->postJson("/api/v1/my/invoices/{$invoice->id}/payment-intent", ['use_saved_card' => true])
         ->assertStatus(201);

    $this->actingAs($buyer, 'sanctum')
         ->postJson("/api/v1/my/invoices/{$invoice->id}/payment-intent")
         ->assertStatus(201);

    // Stripe rejects a key replayed with changed parameters (400 idempotency_error),
    // so a shared key here would hard-fail the buyer's second attempt in production.
    expect($calls)->toHaveCount(2);
    expect($calls[0]['idempotencyKey'])->not->toBe($calls[1]['idempotencyKey']);
    expect($calls[0]['idempotencyKey'])->toEndWith('_saved')
        ->and($calls[1]['idempotencyKey'])->toEndWith('_new');
});

test('a pending saved-card intent is never handed to a new-card request', function () {
    $calls   = fakeStripe();
    $buyer   = User::factory()->create(['status' => 'active']);
    giveSavedCard($buyer);
    $invoice = $this->makeInvoice($buyer);

    InvoicePayment::create([
        'invoice_id'           => $invoice->id,
        'user_id'              => $buyer->id,
        'method'               => 'stripe_card',
        'amount'               => 5800,
        'reference'            => 'pi_existing_saved',
        'stripe_client_secret' => 'pi_existing_saved_secret',
        'card_source'          => 'saved',
        'status'               => 'pending',
    ]);

    $res = $this->actingAs($buyer, 'sanctum')
                ->postJson("/api/v1/my/invoices/{$invoice->id}/payment-intent")
                ->assertStatus(201);

    expect($res->json('data.client_secret'))->not->toBe('pi_existing_saved_secret');
    expect($calls)->toHaveCount(1);
});

test('a pending saved-card intent is reused by a second saved-card request', function () {
    $calls   = fakeStripe();
    $buyer   = User::factory()->create(['status' => 'active']);
    giveSavedCard($buyer);
    $invoice = $this->makeInvoice($buyer);

    InvoicePayment::create([
        'invoice_id'           => $invoice->id,
        'user_id'              => $buyer->id,
        'method'               => 'stripe_card',
        'amount'               => 5800,
        'reference'            => 'pi_existing_saved',
        'stripe_client_secret' => 'pi_existing_saved_secret',
        'card_source'          => 'saved',
        'status'               => 'pending',
    ]);

    $this->actingAs($buyer, 'sanctum')
         ->postJson("/api/v1/my/invoices/{$invoice->id}/payment-intent", ['use_saved_card' => true])
         ->assertOk()
         ->assertJsonPath('data.client_secret', 'pi_existing_saved_secret');

    // Reused from the database — Stripe was never called.
    expect($calls)->toHaveCount(0);
});

test('a legacy pending row with a null card_source is still reused by a new-card request', function () {
    $calls   = fakeStripe();
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    // Written before card_source existed — every such row is a new-card attempt.
    InvoicePayment::create([
        'invoice_id'           => $invoice->id,
        'user_id'              => $buyer->id,
        'method'               => 'stripe_card',
        'amount'               => 5800,
        'reference'            => 'pi_legacy_row',
        'stripe_client_secret' => 'pi_legacy_row_secret',
        'status'               => 'pending',
    ]);

    $this->actingAs($buyer, 'sanctum')
         ->postJson("/api/v1/my/invoices/{$invoice->id}/payment-intent")
         ->assertOk()
         ->assertJsonPath('data.client_secret', 'pi_legacy_row_secret');

    expect($calls)->toHaveCount(0);
});

// ─── Partial amounts ──────────────────────────────────────────────────────────

test('a partial amount charges only what was asked for', function () {
    $calls   = fakeStripe();
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    $this->actingAs($buyer, 'sanctum')
         ->postJson("/api/v1/my/invoices/{$invoice->id}/payment-intent", ['amount' => 1000])
         ->assertStatus(201);

    expect($calls[0]['amountCents'])->toBe(100000);
    expect((float) InvoicePayment::where('invoice_id', $invoice->id)->first()->amount)->toBe(1000.0);
});

test('a partial amount works with the card on file', function () {
    $calls   = fakeStripe();
    $buyer   = User::factory()->create(['status' => 'active']);
    giveSavedCard($buyer);
    $invoice = $this->makeInvoice($buyer);

    $this->actingAs($buyer, 'sanctum')
         ->postJson("/api/v1/my/invoices/{$invoice->id}/payment-intent", [
             'use_saved_card' => true,
             'amount'         => 2500.50,
         ])
         ->assertStatus(201);

    expect($calls[0]['amountCents'])->toBe(250050)
        ->and($calls[0]['customerId'])->toBe('cus_test_' . $buyer->id)
        ->and($calls[0]['metadata']['card_source'])->toBe('saved');
});

test('an amount above the balance due is rejected', function () {
    $calls   = fakeStripe();
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    $this->actingAs($buyer, 'sanctum')
         ->postJson("/api/v1/my/invoices/{$invoice->id}/payment-intent", ['amount' => 5800.01])
         ->assertStatus(422)
         ->assertJsonPath('code', 'amount_exceeds_balance');

    expect($calls)->toHaveCount(0);
    expect(InvoicePayment::where('invoice_id', $invoice->id)->count())->toBe(0);
});

test('two different partial amounts do not share an idempotency key', function () {
    $calls   = fakeStripe();
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    $this->actingAs($buyer, 'sanctum')
         ->postJson("/api/v1/my/invoices/{$invoice->id}/payment-intent", ['amount' => 1000])
         ->assertStatus(201);

    $this->actingAs($buyer, 'sanctum')
         ->postJson("/api/v1/my/invoices/{$invoice->id}/payment-intent", ['amount' => 2000])
         ->assertStatus(201);

    expect($calls[0]['idempotencyKey'])->not->toBe($calls[1]['idempotencyKey']);
});

// ─── Settlement of a partial payment ──────────────────────────────────────────

test('a settled partial payment leaves the invoice partial and the remainder payable', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    InvoicePayment::create([
        'invoice_id'   => $invoice->id,
        'user_id'      => $buyer->id,
        'method'       => 'stripe_card',
        'amount'       => 1000,
        'reference'    => 'pi_partial_settled',
        'card_source'  => 'saved',
        'status'       => 'completed',
        'processed_at' => now(),
    ]);

    $invoice->recalculateBalance();
    $invoice->refresh();

    expect($invoice->status->value)->toBe('partial')
        ->and((float) $invoice->amount_paid)->toBe(1000.0)
        ->and((float) $invoice->balance_due)->toBe(4800.0)
        ->and($invoice->isPaid())->toBeFalse()
        // Release stays gated on payment in full — a partial payment must not
        // unlock the vehicle, the title or the gate pass.
        ->and($invoice->isReleaseEligible())->toBeFalse();

    // The remainder is still chargeable.
    $calls = fakeStripe();
    $this->actingAs($buyer, 'sanctum')
         ->postJson("/api/v1/my/invoices/{$invoice->id}/payment-intent")
         ->assertStatus(201);

    expect($calls[0]['amountCents'])->toBe(480000);
});

test('a partial payment that clears the balance marks the invoice paid', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    foreach ([['pi_part_a', 1000], ['pi_part_b', 4800]] as [$ref, $amount]) {
        InvoicePayment::create([
            'invoice_id'   => $invoice->id,
            'user_id'      => $buyer->id,
            'method'       => 'stripe_card',
            'amount'       => $amount,
            'reference'    => $ref,
            'card_source'  => 'new',
            'status'       => 'completed',
            'processed_at' => now(),
        ]);
    }

    $invoice->recalculateBalance();
    $invoice->refresh();

    expect($invoice->status->value)->toBe('paid')
        ->and((float) $invoice->balance_due)->toBe(0.0)
        ->and($invoice->isPaid())->toBeTrue();
});

// ─── Guards that must keep working ────────────────────────────────────────────

test('use_saved_card still returns 503 when stripe is not configured', function () {
    config(['services.stripe.secret' => '']);

    $buyer = User::factory()->create(['status' => 'active']);
    giveSavedCard($buyer);
    $invoice = $this->makeInvoice($buyer);

    $this->actingAs($buyer, 'sanctum')
         ->postJson("/api/v1/my/invoices/{$invoice->id}/payment-intent", ['use_saved_card' => true])
         ->assertStatus(503);
});

test('use_saved_card cannot be used against another buyers invoice', function () {
    fakeStripe();

    $buyer   = User::factory()->create(['status' => 'active']);
    $other   = User::factory()->create(['status' => 'active']);
    giveSavedCard($other);
    $invoice = $this->makeInvoice($buyer);

    $this->actingAs($other, 'sanctum')
         ->postJson("/api/v1/my/invoices/{$invoice->id}/payment-intent", ['use_saved_card' => true])
         ->assertStatus(404);
});

test('use_saved_card is rejected on a paid invoice', function () {
    fakeStripe();

    $buyer = User::factory()->create(['status' => 'active']);
    giveSavedCard($buyer);
    $invoice = $this->makeInvoice($buyer, [
        'status'      => \App\Enums\InvoiceStatus::Paid,
        'amount_paid' => 5800,
        'balance_due' => 0,
        'paid_at'     => now(),
    ]);

    $this->actingAs($buyer, 'sanctum')
         ->postJson("/api/v1/my/invoices/{$invoice->id}/payment-intent", ['use_saved_card' => true])
         ->assertStatus(422)
         ->assertJsonPath('code', 'invoice_not_open');
});

test('a non-boolean use_saved_card is rejected rather than silently ignored', function () {
    fakeStripe();

    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    $this->actingAs($buyer, 'sanctum')
         ->postJson("/api/v1/my/invoices/{$invoice->id}/payment-intent", ['use_saved_card' => 'banana'])
         ->assertStatus(422);
});
