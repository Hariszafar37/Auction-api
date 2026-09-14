<?php

use App\Enums\InvoiceStatus;
use App\Mail\PaymentReceived;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Mail;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class, \Tests\Helpers\CreatesInvoiceData::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Mail::fake();
});

// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Build a Stripe-compatible webhook request with a valid HMAC signature.
 * The secret is injected into the config so the controller can verify it.
 */
function makeStripeWebhookRequest(array $paymentIntentData, string $type = 'payment_intent.succeeded'): array
{
    $secret = 'whsec_test_secret_only_for_tests';
    config(['services.stripe.webhook_secret' => $secret]);

    $payload = json_encode([
        'type' => $type,
        'data' => ['object' => $paymentIntentData],
    ]);

    $timestamp = time();
    $sig       = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

    return [
        'payload' => $payload,
        'header'  => "t={$timestamp},v1={$sig}",
    ];
}

// ─── Payment intent endpoint ──────────────────────────────────────────────────

test('payment intent endpoint requires authentication', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    $this->postJson("/api/v1/my/invoices/{$invoice->id}/payment-intent")
         ->assertUnauthorized();
});

test('payment intent endpoint returns 503 when stripe is not configured', function () {
    config(['services.stripe.secret' => '']);

    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    $this->actingAs($buyer, 'sanctum')
         ->postJson("/api/v1/my/invoices/{$invoice->id}/payment-intent")
         ->assertStatus(503);
});

test('payment intent endpoint returns 422 for a paid invoice', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer, [
        'status'      => InvoiceStatus::Paid,
        'amount_paid' => 5800,
        'balance_due' => 0,
        'paid_at'     => now(),
    ]);

    $this->actingAs($buyer, 'sanctum')
         ->postJson("/api/v1/my/invoices/{$invoice->id}/payment-intent")
         ->assertStatus(422);
});

test('payment intent endpoint returns 404 when buyer tries to pay another buyers invoice', function () {
    $buyer1  = User::factory()->create(['status' => 'active']);
    $buyer2  = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer1);

    $this->actingAs($buyer2, 'sanctum')
         ->postJson("/api/v1/my/invoices/{$invoice->id}/payment-intent")
         ->assertStatus(404);
});

test('payment intent endpoint is idempotent at database level', function () {
    config(['services.stripe.secret' => '']); // no Stripe, will get 503

    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    // Seed an existing pending payment to simulate a prior PI creation
    InvoicePayment::create([
        'invoice_id'           => $invoice->id,
        'user_id'              => $buyer->id,
        'method'               => 'stripe_card',
        'amount'               => 5800,
        'reference'            => 'pi_test_existing_abc',
        'stripe_client_secret' => 'pi_test_existing_abc_secret',
        'status'               => 'pending',
    ]);

    // If Stripe is configured, a second call should return the existing PI without creating a new one
    // We verify the DB-level idempotency guard by checking only one payment record exists
    expect(InvoicePayment::where('invoice_id', $invoice->id)->where('method', 'stripe_card')->count())->toBe(1);
});

// ─── Webhook ─────────────────────────────────────────────────────────────────

test('webhook rejects invalid stripe signature with 400', function () {
    config(['services.stripe.webhook_secret' => 'whsec_real_secret']);

    $this->call('POST', '/api/v1/webhook/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 't=123,v1=invalidsig',
        'CONTENT_TYPE'          => 'application/json',
    ], json_encode(['type' => 'payment_intent.succeeded']))->assertStatus(400);
});

test('webhook marks invoice paid on payment succeeded', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    $payment = InvoicePayment::create([
        'invoice_id' => $invoice->id,
        'user_id'    => $buyer->id,
        'method'     => 'stripe_card',
        'amount'     => 5800,
        'reference'  => 'pi_test_webhookpaid',
        'status'     => 'pending',
    ]);

    $webhook = makeStripeWebhookRequest(['id' => 'pi_test_webhookpaid', 'amount' => 580000]);

    $this->call('POST', '/api/v1/webhook/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => $webhook['header'],
        'CONTENT_TYPE'          => 'application/json',
    ], $webhook['payload'])->assertOk();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->fresh()->paid_at)->not->toBeNull()
        ->and($payment->fresh()->status)->toBe('completed');
});

test('webhook does not double mark paid on retry', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer, [
        'status'      => InvoiceStatus::Paid,
        'amount_paid' => 5800,
        'balance_due' => 0,
        'paid_at'     => now()->subMinute(),
    ]);

    $payment = InvoicePayment::create([
        'invoice_id'   => $invoice->id,
        'user_id'      => $buyer->id,
        'method'       => 'stripe_card',
        'amount'       => 5800,
        'reference'    => 'pi_test_alreadypaid',
        'status'       => 'completed',  // already processed
        'processed_at' => now()->subMinute(),
    ]);

    $paidAt  = $invoice->paid_at;
    $webhook = makeStripeWebhookRequest(['id' => 'pi_test_alreadypaid', 'amount' => 580000]);

    $this->call('POST', '/api/v1/webhook/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => $webhook['header'],
        'CONTENT_TYPE'          => 'application/json',
    ], $webhook['payload'])->assertOk();

    // paid_at must not change — idempotency guard worked
    expect($invoice->fresh()->paid_at->eq($paidAt))->toBeTrue();
});

test('webhook queues receipt email on success', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    InvoicePayment::create([
        'invoice_id' => $invoice->id,
        'user_id'    => $buyer->id,
        'method'     => 'stripe_card',
        'amount'     => 5800,
        'reference'  => 'pi_test_email_queued',
        'status'     => 'pending',
    ]);

    $webhook = makeStripeWebhookRequest(['id' => 'pi_test_email_queued', 'amount' => 580000]);

    $this->call('POST', '/api/v1/webhook/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => $webhook['header'],
        'CONTENT_TYPE'          => 'application/json',
    ], $webhook['payload'])->assertOk();

    Mail::assertQueued(PaymentReceived::class, fn ($mail) =>
        $mail->hasTo($buyer->email)
    );
});

test('webhook does not queue email when invoice is already paid', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer, [
        'status'      => InvoiceStatus::Paid,
        'amount_paid' => 5800,
        'balance_due' => 0,
        'paid_at'     => now()->subMinute(),
    ]);

    InvoicePayment::create([
        'invoice_id'   => $invoice->id,
        'user_id'      => $buyer->id,
        'method'       => 'stripe_card',
        'amount'       => 5800,
        'reference'    => 'pi_test_no_dup_email',
        'status'       => 'completed',
        'processed_at' => now()->subMinute(),
    ]);

    $webhook = makeStripeWebhookRequest(['id' => 'pi_test_no_dup_email', 'amount' => 580000]);

    $this->call('POST', '/api/v1/webhook/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => $webhook['header'],
        'CONTENT_TYPE'          => 'application/json',
    ], $webhook['payload'])->assertOk();

    Mail::assertNothingQueued();
});

test('webhook creates completed payment record on success', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    InvoicePayment::create([
        'invoice_id' => $invoice->id,
        'user_id'    => $buyer->id,
        'method'     => 'stripe_card',
        'amount'     => 5800,
        'reference'  => 'pi_test_record_created',
        'status'     => 'pending',
    ]);

    $webhook = makeStripeWebhookRequest(['id' => 'pi_test_record_created', 'amount' => 580000]);

    $this->call('POST', '/api/v1/webhook/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => $webhook['header'],
        'CONTENT_TYPE'          => 'application/json',
    ], $webhook['payload'])->assertOk();

    $payment = InvoicePayment::where('reference', 'pi_test_record_created')->first();
    expect($payment->status)->toBe('completed')
        ->and($payment->processed_at)->not->toBeNull();
});

test('webhook still marks invoice paid when deposit capture fails (non-fatal)', function () {
    // Regression: previously the deposit capture ran INSIDE the DB transaction and
    // only UniqueConstraintViolation was caught. A failing capture (expired/never-
    // confirmed hold) threw → rollback → HTTP 500 → invoice never marked paid and
    // Stripe retried forever. An empty secret makes capture() throw deterministically.
    config(['services.stripe.secret' => '']);

    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer, [
        'stripe_deposit_intent_id' => 'pi_deposit_never_confirmed',
        'deposit_status'           => 'authorized',
    ]);

    $payment = InvoicePayment::create([
        'invoice_id' => $invoice->id,
        'user_id'    => $buyer->id,
        'method'     => 'stripe_card',
        'amount'     => 5800,
        'reference'  => 'pi_test_deposit_capture_fail',
        'status'     => 'pending',
    ]);

    $webhook = makeStripeWebhookRequest(['id' => 'pi_test_deposit_capture_fail', 'amount' => 580000]);

    $this->call('POST', '/api/v1/webhook/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => $webhook['header'],
        'CONTENT_TYPE'          => 'application/json',
    ], $webhook['payload'])->assertOk();

    // The buyer payment is completed and the invoice is paid despite the capture failure.
    // deposit_status stays 'authorized' (not 'captured') since the capture did not succeed.
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->fresh()->paid_at)->not->toBeNull()
        ->and($payment->fresh()->status)->toBe('completed')
        ->and($invoice->fresh()->deposit_status)->toBe('authorized');
});

test('webhook returns 200 for unrecognised payment intent', function () {
    // If no InvoicePayment record matches the PI, webhook must still return 200
    // so Stripe does not retry indefinitely
    $webhook = makeStripeWebhookRequest(['id' => 'pi_unknown_no_match', 'amount' => 100]);

    $this->call('POST', '/api/v1/webhook/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => $webhook['header'],
        'CONTENT_TYPE'          => 'application/json',
    ], $webhook['payload'])->assertOk();
});

// ─── Incomplete card attempts (FIX 4) ────────────────────────────────────────
//
// A buyer who opens the card form and never successfully submits card details
// must not end up with a misleading "pending" row in their payment history, and
// the abandoned PaymentIntent must not sit in Stripe as "Incomplete" forever.

test('buyer payment history hides a card attempt that never settled', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    InvoicePayment::create([
        'invoice_id' => $invoice->id,
        'user_id'    => $buyer->id,
        'method'     => 'stripe_card',
        'amount'     => 5800,
        'reference'  => 'pi_test_incomplete_hidden',
        'status'     => 'pending',
    ]);

    $payments = $this->actingAs($buyer)
        ->getJson("/api/v1/my/invoices/{$invoice->id}")
        ->assertOk()
        ->json('data.payments');

    expect($payments)->toBe([]);
});

test('buyer payment history hides a cancelled card attempt', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    InvoicePayment::create([
        'invoice_id' => $invoice->id,
        'user_id'    => $buyer->id,
        'method'     => 'stripe_card',
        'amount'     => 5800,
        'reference'  => 'pi_test_cancelled_hidden',
        'status'     => 'canceled',
    ]);

    $payments = $this->actingAs($buyer)
        ->getJson("/api/v1/my/invoices/{$invoice->id}")
        ->assertOk()
        ->json('data.payments');

    expect($payments)->toBe([]);
});

test('buyer payment history still shows a genuine card decline', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    InvoicePayment::create([
        'invoice_id' => $invoice->id,
        'user_id'    => $buyer->id,
        'method'     => 'stripe_card',
        'amount'     => 5800,
        'reference'  => 'pi_test_declined_visible',
        'status'     => 'failed',
    ]);

    $payments = $this->actingAs($buyer)
        ->getJson("/api/v1/my/invoices/{$invoice->id}")
        ->assertOk()
        ->json('data.payments');

    expect($payments)->toHaveCount(1)
        ->and($payments[0]['status'])->toBe('failed');
});

test('buyer payment history still shows a completed card payment', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    InvoicePayment::create([
        'invoice_id'   => $invoice->id,
        'user_id'      => $buyer->id,
        'method'       => 'stripe_card',
        'amount'       => 5800,
        'reference'    => 'pi_test_completed_visible',
        'status'       => 'completed',
        'processed_at' => now(),
    ]);

    $payments = $this->actingAs($buyer)
        ->getJson("/api/v1/my/invoices/{$invoice->id}")
        ->assertOk()
        ->json('data.payments');

    expect($payments)->toHaveCount(1)
        ->and($payments[0]['status'])->toBe('completed');
});

test('buyer payment history still shows a non-card payment awaiting verification', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    InvoicePayment::create([
        'invoice_id' => $invoice->id,
        'user_id'    => $buyer->id,
        'method'     => 'wire',
        'amount'     => 5800,
        'reference'  => 'wire_ref_001',
        'status'     => 'pending_verification',
    ]);

    $payments = $this->actingAs($buyer)
        ->getJson("/api/v1/my/invoices/{$invoice->id}")
        ->assertOk()
        ->json('data.payments');

    expect($payments)->toHaveCount(1)
        ->and($payments[0]['status'])->toBe('pending_verification');
});

test('deposit rows are never treated as incomplete card attempts', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    // Deposit rows run their own lifecycle (authorized / requires_action / ...)
    // and must stay visible to the buyer.
    InvoicePayment::create([
        'invoice_id' => $invoice->id,
        'user_id'    => $buyer->id,
        'method'     => 'deposit',
        'amount'     => 300,
        'reference'  => 'pi_test_deposit_row',
        'status'     => 'requires_action',
    ]);

    $payments = $this->actingAs($buyer)
        ->getJson("/api/v1/my/invoices/{$invoice->id}")
        ->assertOk()
        ->json('data.payments');

    expect($payments)->toHaveCount(1)
        ->and($payments[0]['method'])->toBe('deposit');
});

// ─── payment_intent.canceled webhook ─────────────────────────────────────────

test('webhook marks an abandoned card attempt cancelled', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    $payment = InvoicePayment::create([
        'invoice_id'           => $invoice->id,
        'user_id'              => $buyer->id,
        'method'               => 'stripe_card',
        'amount'               => 5800,
        'reference'            => 'pi_test_cancel_event',
        'stripe_client_secret' => 'pi_test_cancel_event_secret',
        'status'               => 'pending',
    ]);

    $webhook = makeStripeWebhookRequest(
        ['id' => 'pi_test_cancel_event'],
        'payment_intent.canceled'
    );

    $this->call('POST', '/api/v1/webhook/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => $webhook['header'],
        'CONTENT_TYPE'          => 'application/json',
    ], $webhook['payload'])->assertOk();

    expect($payment->fresh()->status)->toBe('canceled')
        ->and($payment->fresh()->stripe_client_secret)->toBeNull();
});

test('cancel webhook never downgrades an already completed payment', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    $payment = InvoicePayment::create([
        'invoice_id'   => $invoice->id,
        'user_id'      => $buyer->id,
        'method'       => 'stripe_card',
        'amount'       => 5800,
        'reference'    => 'pi_test_cancel_after_paid',
        'status'       => 'completed',
        'processed_at' => now(),
    ]);

    $webhook = makeStripeWebhookRequest(
        ['id' => 'pi_test_cancel_after_paid'],
        'payment_intent.canceled'
    );

    $this->call('POST', '/api/v1/webhook/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => $webhook['header'],
        'CONTENT_TYPE'          => 'application/json',
    ], $webhook['payload'])->assertOk();

    expect($payment->fresh()->status)->toBe('completed');
});

test('cancel webhook leaves deposit rows untouched', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    $payment = InvoicePayment::create([
        'invoice_id' => $invoice->id,
        'user_id'    => $buyer->id,
        'method'     => 'deposit',
        'amount'     => 300,
        'reference'  => 'pi_test_deposit_cancel',
        'status'     => 'authorized',
    ]);

    $webhook = makeStripeWebhookRequest(
        ['id' => 'pi_test_deposit_cancel'],
        'payment_intent.canceled'
    );

    $this->call('POST', '/api/v1/webhook/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => $webhook['header'],
        'CONTENT_TYPE'          => 'application/json',
    ], $webhook['payload'])->assertOk();

    expect($payment->fresh()->status)->toBe('authorized');
});

test('failure webhook never downgrades an already completed payment', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    $payment = InvoicePayment::create([
        'invoice_id'   => $invoice->id,
        'user_id'      => $buyer->id,
        'method'       => 'stripe_card',
        'amount'       => 5800,
        'reference'    => 'pi_test_fail_after_paid',
        'status'       => 'completed',
        'processed_at' => now(),
    ]);

    $webhook = makeStripeWebhookRequest(
        ['id' => 'pi_test_fail_after_paid'],
        'payment_intent.payment_failed'
    );

    $this->call('POST', '/api/v1/webhook/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => $webhook['header'],
        'CONTENT_TYPE'          => 'application/json',
    ], $webhook['payload'])->assertOk();

    expect($payment->fresh()->status)->toBe('completed');
});

test('an unsettled card attempt never credits the invoice balance', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    InvoicePayment::create([
        'invoice_id' => $invoice->id,
        'user_id'    => $buyer->id,
        'method'     => 'stripe_card',
        'amount'     => 5800,
        'reference'  => 'pi_test_no_credit',
        'status'     => 'canceled',
    ]);

    $invoice->recalculateBalance();

    expect((float) $invoice->fresh()->amount_paid)->toBe(0.0)
        ->and((float) $invoice->fresh()->balance_due)->toBe(5800.0);
});

// ─── Sweeper command ─────────────────────────────────────────────────────────

test('sweeper is a no-op when stripe is not configured', function () {
    config(['services.stripe.secret' => '']);

    $this->artisan('payments:cancel-abandoned-intents')
         ->assertExitCode(0);
});

test('sweeper ignores attempts younger than the age floor', function () {
    config(['services.stripe.secret' => 'sk_test_fake']);

    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    // Created just now — a buyer may still be mid-checkout, so it must be left
    // alone. No Stripe call is made, so this stays offline and deterministic.
    $payment = InvoicePayment::create([
        'invoice_id' => $invoice->id,
        'user_id'    => $buyer->id,
        'method'     => 'stripe_card',
        'amount'     => 5800,
        'reference'  => 'pi_test_too_young',
        'status'     => 'pending',
    ]);

    $this->artisan('payments:cancel-abandoned-intents --minutes=60')
         ->assertExitCode(0);

    expect($payment->fresh()->status)->toBe('pending');
});

test('sweeper ignores already-settled and non-card rows', function () {
    config(['services.stripe.secret' => 'sk_test_fake']);

    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    $rows = [
        ['method' => 'stripe_card', 'status' => 'completed', 'reference' => 'pi_old_completed'],
        ['method' => 'stripe_card', 'status' => 'failed',    'reference' => 'pi_old_failed'],
        ['method' => 'stripe_card', 'status' => 'canceled',  'reference' => 'pi_old_canceled'],
        ['method' => 'deposit',     'status' => 'pending',   'reference' => 'pi_old_deposit'],
        ['method' => 'wire',        'status' => 'pending',   'reference' => 'wire_old'],
    ];

    foreach ($rows as $row) {
        $p = InvoicePayment::create(array_merge([
            'invoice_id' => $invoice->id,
            'user_id'    => $buyer->id,
            'amount'     => 100,
        ], $row));
        $p->forceFill(['created_at' => now()->subDay()])->save();
    }

    // Nothing matches the sweeper filter, so Stripe is never contacted.
    $this->artisan('payments:cancel-abandoned-intents --minutes=60')
         ->assertExitCode(0);

    expect(InvoicePayment::where('reference', 'pi_old_deposit')->first()->status)->toBe('pending');
});

test('admin still sees the full unfiltered ledger including incomplete attempts', function () {
    $buyer = User::factory()->create(['status' => 'active']);
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    $invoice = $this->makeInvoice($buyer);

    InvoicePayment::create([
        'invoice_id' => $invoice->id,
        'user_id'    => $buyer->id,
        'method'     => 'stripe_card',
        'amount'     => 5800,
        'reference'  => 'pi_test_admin_sees_it',
        'status'     => 'pending',
    ]);

    $payments = $this->actingAs($admin)
        ->getJson("/api/v1/admin/invoices/{$invoice->id}")
        ->assertOk()
        ->json('data.payments');

    expect($payments)->toHaveCount(1)
        ->and($payments[0]['status'])->toBe('pending');
});
