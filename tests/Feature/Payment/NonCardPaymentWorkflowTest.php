<?php

use App\Enums\InvoiceStatus;
use App\Enums\PickupStatus;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\PaymentAcknowledgement;
use App\Models\PaymentSetting;
use App\Models\PurchaseDetail;
use App\Models\User;
use App\Services\Payment\PaymentLedgerService;
use Database\Seeders\RolePermissionSeeder;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class, \Tests\Helpers\CreatesInvoiceData::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

// ─── Ledger math ───────────────────────────────────────────────────────────────

test('a positive charge adjustment increases the effective total and balance', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer); // total 5800
    $admin   = $this->makeAdmin();

    app(PaymentLedgerService::class)->applyAdjustment($invoice, 50, 'Late fee', $admin);

    $invoice->refresh();
    expect((float) $invoice->adjustments_total)->toBe(50.0)
        ->and((float) $invoice->effective_total)->toBe(5850.0)
        ->and((float) $invoice->balance_due)->toBe(5850.0);
});

test('a negative adjustment that exceeds payments still never drives the balance below zero', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer); // total 5800
    $admin   = $this->makeAdmin();

    // Fully pay, then apply a goodwill discount → overpayment becomes refund_due.
    $this->makePayment($invoice, 5800, 'wire');
    app(PaymentLedgerService::class)->applyAdjustment($invoice, -500, 'Goodwill discount', $admin);

    $invoice->refresh();
    expect((float) $invoice->effective_total)->toBe(5300.0)
        ->and((float) $invoice->balance_due)->toBe(0.0)
        ->and((float) $invoice->refund_due)->toBe(500.0)
        ->and($invoice->status)->toBe(InvoiceStatus::Paid);
});

test('reversing a verified payment removes its credit from the balance', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);
    $admin   = $this->makeAdmin();

    $payment = $this->makePayment($invoice, 5800, 'cash'); // status completed
    expect((float) $invoice->fresh()->balance_due)->toBe(0.0);

    app(PaymentLedgerService::class)->reversePayment($invoice, $payment, 'Recorded in error', $admin);

    $invoice->refresh();
    expect((float) $invoice->amount_paid)->toBe(0.0)
        ->and((float) $invoice->balance_due)->toBe(5800.0);
    // Original row is preserved for audit.
    expect(InvoicePayment::where('invoice_id', $invoice->id)->count())->toBe(2);
});

// ─── Release eligibility ─────────────────────────────────────────────────────────

test('a pending_verification payment blocks release even when the balance is zero', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    $this->makePayment($invoice, 5800, 'wire'); // paid in full

    // A second, still-unverified submission exists.
    InvoicePayment::create([
        'invoice_id'       => $invoice->id,
        'user_id'          => $buyer->id,
        'method'           => 'wire',
        'transaction_type' => 'payment',
        'amount'           => 100,
        'status'           => 'pending_verification',
    ]);

    expect($invoice->fresh()->isReleaseEligible())->toBeFalse();
});

// ─── Buyer non-card workflow ─────────────────────────────────────────────────────

test('buyer can fetch payment options with enabled methods and instructions', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    $this->actingAs($buyer, 'sanctum')
        ->getJson("/api/v1/my/invoices/{$invoice->id}/payment-options")
        ->assertOk()
        ->assertJsonPath('data.methods.wire.enabled', true)
        ->assertJsonPath('data.methods.cash.instructions.location', fn ($v) => str_contains($v, 'Colonial'))
        ->assertJsonPath('data.business_days_to_pay', 2);
});

test('acknowledging applies the 2-business-day deadline and is audited', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer, ['due_at' => now()->addDays(7)]);

    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/my/invoices/{$invoice->id}/acknowledge", [
            'payment_method' => 'wire',
            'acknowledged'   => true,
        ])
        ->assertCreated();

    $ack = PaymentAcknowledgement::where('invoice_id', $invoice->id)->first();
    expect($ack)->not->toBeNull()
        ->and($ack->user_id)->toBe($buyer->id);

    $due = $invoice->fresh()->due_at;
    expect($due->isWeekend())->toBeFalse()
        ->and($due->isFuture())->toBeTrue();
    // Balance is untouched by acknowledgment.
    expect((float) $invoice->fresh()->balance_due)->toBe(5800.0);
});

test('submitting a non-card payment requires a prior acknowledgment', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/my/invoices/{$invoice->id}/submit-payment", [
            'method'    => 'wire',
            'amount'    => 1000,
            'reference' => 'WIRE-123',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'acknowledgment_required');
});

test('a submitted non-card payment is pending and does not change the balance', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    // Acknowledge first.
    $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/my/invoices/{$invoice->id}/acknowledge", [
        'payment_method' => 'wire',
        'acknowledged'   => true,
    ])->assertCreated();

    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/my/invoices/{$invoice->id}/submit-payment", [
            'method'    => 'wire',
            'amount'    => 1000,
            'reference' => 'WIRE-123',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending_verification');

    $invoice->refresh();
    expect((float) $invoice->amount_paid)->toBe(0.0)
        ->and((float) $invoice->balance_due)->toBe(5800.0)
        ->and($invoice->status)->toBe(InvoiceStatus::Pending);
});

test('submit is rejected when the amount exceeds the balance', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/my/invoices/{$invoice->id}/acknowledge", [
        'payment_method' => 'cash',
        'acknowledged'   => true,
    ])->assertCreated();

    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/my/invoices/{$invoice->id}/submit-payment", [
            'method' => 'cash',
            'amount' => 9999,
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'amount_exceeds_balance');
});

test('verifying a buyer submission via approve credits the balance', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);
    $admin   = $this->makeAdmin();

    $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/my/invoices/{$invoice->id}/acknowledge", [
        'payment_method' => 'wire',
        'acknowledged'   => true,
    ])->assertCreated();

    $resp = $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/my/invoices/{$invoice->id}/submit-payment", [
        'method'    => 'wire',
        'amount'    => 5800,
        'reference' => 'WIRE-FULL',
    ])->assertCreated();

    $paymentId = $resp->json('data.id');

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/invoices/{$invoice->id}/payments/{$paymentId}/approve", ['note' => 'Funds confirmed'])
        ->assertOk();

    $invoice->refresh();
    expect((float) $invoice->balance_due)->toBe(0.0)
        ->and($invoice->status)->toBe(InvoiceStatus::Paid);
});

// ─── Admin endpoints & authorization ─────────────────────────────────────────────

test('admin can apply and update payment settings; non-admin cannot', function () {
    $admin = $this->makeAdmin();
    $buyer = User::factory()->create(['status' => 'active']);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/admin/payment-settings')
        ->assertOk()
        ->assertJsonPath('data.business_days_to_pay', 2);

    $this->actingAs($admin, 'sanctum')
        ->putJson('/api/v1/admin/payment-settings', [
            'business_days_to_pay' => 3,
            'wire_bank_name'       => 'First National',
        ])
        ->assertOk()
        ->assertJsonPath('data.business_days_to_pay', 3)
        ->assertJsonPath('data.wire_bank_name', 'First National');

    expect((int) PaymentSetting::current()->business_days_to_pay)->toBe(3);

    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/admin/payment-settings')
        ->assertForbidden();
});

test('admin late-fee endpoint applies the configured fee as an adjustment', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);
    $admin   = $this->makeAdmin();

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/invoices/{$invoice->id}/late-fee")
        ->assertCreated()
        ->assertJsonPath('data.effective_total', fn ($v) => (float) $v === 5850.0);

    expect(InvoicePayment::where('invoice_id', $invoice->id)
        ->where('transaction_type', 'adjustment')->count())->toBe(1);
});

test('non-admin cannot apply an adjustment', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/admin/invoices/{$invoice->id}/adjustments", [
            'amount' => 100,
            'reason' => 'nope',
        ])
        ->assertForbidden();
});

// ─── Adjustment fee type (display label) ───────────────────────────────────────

test('an adjustment stores the fee type and exposes it as the display label', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);
    $admin   = $this->makeAdmin();

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/invoices/{$invoice->id}/adjustments", [
            'amount'   => 50,
            'fee_type' => 'Storage Fee',
            'reason'   => 'Held past free period',
        ])
        ->assertCreated();

    $entry = InvoicePayment::where('invoice_id', $invoice->id)
        ->where('transaction_type', 'adjustment')->firstOrFail();

    // Internal type is still 'adjustment' (calculations/filters unaffected).
    expect($entry->transaction_type->value)->toBe('adjustment')
        ->and($entry->fee_type)->toBe('Storage Fee')
        ->and($entry->notes)->toBe('Held past free period')
        ->and($entry->adjustmentTitle())->toBe('Storage Fee')
        ->and((float) $invoice->fresh()->effective_total)->toBe(5850.0);
});

test('applying an adjustment requires a fee type', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);
    $admin   = $this->makeAdmin();

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/invoices/{$invoice->id}/adjustments", [
            'amount' => 50,
            'reason' => 'No fee type provided',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['fee_type']);
});

test('a legacy adjustment without a fee type falls back to its reason for display', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);
    $admin   = $this->makeAdmin();

    // Service still accepts a null fee type (back-compat for existing callers).
    $entry = app(PaymentLedgerService::class)
        ->applyAdjustment($invoice, 75, 'Document handling', $admin);

    expect($entry->fee_type)->toBeNull()
        ->and($entry->adjustmentTitle())->toBe('Document handling');
});

test('the late-fee endpoint labels the adjustment as a Late Payment Fee', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);
    $admin   = $this->makeAdmin();

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/invoices/{$invoice->id}/late-fee")
        ->assertCreated();

    $entry = InvoicePayment::where('invoice_id', $invoice->id)
        ->where('transaction_type', 'adjustment')->firstOrFail();

    expect($entry->fee_type)->toBe('Late Payment Fee')
        ->and($entry->adjustmentTitle())->toBe('Late Payment Fee');
});

// ─── Release override ─────────────────────────────────────────────────────────────

test('admin override-release unblocks an unpaid lot, requires a reason, and is audited', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer); // unpaid
    $admin   = $this->makeAdmin();

    $purchase = PurchaseDetail::create([
        'lot_id'        => $invoice->lot_id,
        'buyer_id'      => $buyer->id,
        'pickup_status' => PickupStatus::AwaitingPayment,
    ]);

    expect($purchase->canRelease())->toBeFalse();

    // Reason is required.
    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/purchases/{$invoice->lot_id}/override-release", [])
        ->assertStatus(422);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/purchases/{$invoice->lot_id}/override-release", [
            'reason' => 'Buyer paying at pickup, approved by manager',
        ])
        ->assertOk()
        ->assertJsonPath('data.can_release', true);

    $purchase->refresh();
    expect($purchase->isReleaseOverridden())->toBeTrue()
        ->and($purchase->release_overridden_by)->toBe($admin->id)
        ->and($purchase->pickup_status)->toBe(PickupStatus::ReadyForPickup);
});

test('gate pass verify reflects release eligibility and override', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer); // unpaid
    $admin   = $this->makeAdmin();

    $purchase = PurchaseDetail::create([
        'lot_id'          => $invoice->lot_id,
        'buyer_id'        => $buyer->id,
        'pickup_status'   => PickupStatus::AwaitingPayment,
        'gate_pass_token' => 'tok-test-123',
    ]);

    // Unpaid → invalid.
    $this->getJson('/api/v1/verify/gate-pass/tok-test-123')
        ->assertOk()
        ->assertJsonPath('valid', false);

    // Override → valid.
    app(\App\Services\Pickup\PurchaseService::class)->overrideRelease($purchase, 'manager approved', $admin->id);

    $this->getJson('/api/v1/verify/gate-pass/tok-test-123')
        ->assertOk()
        ->assertJsonPath('valid', true);
});
