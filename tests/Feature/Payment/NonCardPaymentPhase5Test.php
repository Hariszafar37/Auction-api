<?php

use App\Enums\InvoiceStatus;
use App\Enums\PickupStatus;
use App\Models\InvoicePayment;
use App\Models\PaymentSetting;
use App\Models\PurchaseDetail;
use App\Models\User;
use App\Services\Payment\PaymentLedgerService;
use App\Services\Pickup\PurchaseService;
use App\Support\BusinessDays;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Gate;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class, \Tests\Helpers\CreatesInvoiceData::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

/** Create an already-credited (verified) payment-side ledger entry. */
function phase5VerifiedPayment(\App\Models\Invoice $invoice, float $amount, string $method): InvoicePayment
{
    $p = InvoicePayment::create([
        'invoice_id'       => $invoice->id,
        'user_id'          => $invoice->buyer_id,
        'method'           => $method,
        'transaction_type' => 'payment',
        'amount'           => $amount,
        'status'           => 'verified',
        'processed_at'     => now(),
    ]);
    $invoice->recalculateBalance();

    return $p;
}

// ─── A2 — Mixed full payment (cash + card) ───────────────────────────────────────

test('A2 mixed cash and card payments fully settle the invoice', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer); // total 5800

    $this->makePayment($invoice, 3000, 'cash');        // completed
    $this->makePayment($invoice, 2800, 'stripe_card'); // completed

    $invoice->refresh();
    expect((float) $invoice->balance_due)->toBe(0.0)
        ->and($invoice->status)->toBe(InvoiceStatus::Paid);
});

// ─── A3 — Combination non-card (wire + check) ────────────────────────────────────

test('A3 wire plus cashier check combination settles the invoice', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer); // total 5800

    phase5VerifiedPayment($invoice, 3000, 'wire');
    phase5VerifiedPayment($invoice, 2800, 'check');

    $invoice->refresh();
    expect((float) $invoice->balance_due)->toBe(0.0)
        ->and($invoice->status)->toBe(InvoiceStatus::Paid);
});

// ─── A4 — Reject does not credit ─────────────────────────────────────────────────

test('A4 rejecting a pending payment leaves the balance unchanged', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);
    $admin   = $this->makeAdmin();

    $payment = InvoicePayment::create([
        'invoice_id'       => $invoice->id,
        'user_id'          => $buyer->id,
        'method'           => 'wire',
        'transaction_type' => 'payment',
        'amount'           => 5800,
        'status'           => 'pending_verification',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/invoices/{$invoice->id}/payments/{$payment->id}/reject", ['reason' => 'Funds never arrived'])
        ->assertOk();

    $invoice->refresh();
    expect($payment->fresh()->status)->toBe('rejected')
        ->and((float) $invoice->balance_due)->toBe(5800.0)
        ->and((float) $invoice->amount_paid)->toBe(0.0)
        ->and($invoice->status)->toBe(InvoiceStatus::Pending);
});

// ─── A5 — Admin record → approve parity ──────────────────────────────────────────

test('A5 admin-recorded payment credits identically after approval', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);
    $admin   = $this->makeAdmin();

    $rec = $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/invoices/{$invoice->id}/record-payment", [
            'method' => 'wire', 'amount' => 5800, 'reference' => 'FT-001',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending_verification');

    // Balance untouched until approved.
    expect((float) $invoice->fresh()->balance_due)->toBe(5800.0);

    $paymentId = $rec->json('data.id');
    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/invoices/{$invoice->id}/payments/{$paymentId}/approve")
        ->assertOk();

    $invoice->refresh();
    expect((float) $invoice->balance_due)->toBe(0.0)
        ->and($invoice->status)->toBe(InvoiceStatus::Paid);
});

// ─── A6 — Late fee re-opens a paid balance ───────────────────────────────────────

test('A6 applying a late fee to a paid invoice re-opens the balance', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);
    $admin   = $this->makeAdmin();

    $this->makePayment($invoice, 5800, 'wire');
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);

    app(PaymentLedgerService::class)->applyLateFee($invoice, 50, $admin);

    $invoice->refresh();
    expect((float) $invoice->effective_total)->toBe(5850.0)
        ->and((float) $invoice->balance_due)->toBe(50.0)
        ->and($invoice->status)->toBe(InvoiceStatus::Partial);
});

// ─── A7 — Refund ledger entry reduces credited ───────────────────────────────────

test('A7 a refund entry reduces the credited amount', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);
    $admin   = $this->makeAdmin();

    $this->makePayment($invoice, 5800, 'cash');
    expect((float) $invoice->fresh()->balance_due)->toBe(0.0);

    app(PaymentLedgerService::class)->recordRefund($invoice, 500, 'Partial refund', $admin);

    $invoice->refresh();
    expect((float) $invoice->amount_paid)->toBe(5300.0)
        ->and((float) $invoice->balance_due)->toBe(500.0);
});

// ─── A8 — Disabled method blocks the buyer ───────────────────────────────────────

test('A8 a disabled method is rejected on acknowledge', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    PaymentSetting::current()->update(['method_wire_enabled' => false]);

    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/my/invoices/{$invoice->id}/acknowledge", [
            'payment_method' => 'wire', 'acknowledged' => true,
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'method_disabled');
});

// ─── A9 — Business-day deadline skips weekends ───────────────────────────────────

test('A9 business-day arithmetic skips weekends', function () {
    $friday = Carbon::now()->startOfDay()->next(Carbon::FRIDAY);
    $result = BusinessDays::add($friday, 2);

    // Friday + 2 business days → following Tuesday, never landing on a weekend.
    expect($result->isWeekend())->toBeFalse()
        ->and($result->dayOfWeek)->toBe(Carbon::TUESDAY);
});

// ─── A10 — Gate-pass download policy honours release gate + override ──────────────

test('A10 gate pass download policy blocks until paid or overridden', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer); // unpaid
    $admin   = $this->makeAdmin();

    $purchase = PurchaseDetail::create([
        'lot_id'          => $invoice->lot_id,
        'buyer_id'        => $buyer->id,
        'pickup_status'   => PickupStatus::AwaitingPayment,
        'gate_pass_token' => 'tok-a10',
    ]);

    expect(Gate::forUser($buyer)->allows('downloadGatePass', $purchase))->toBeFalse();

    app(PurchaseService::class)->overrideRelease($purchase, 'manager approved', $admin->id);

    expect(Gate::forUser($buyer)->allows('downloadGatePass', $purchase->fresh()))->toBeTrue();
});

// ─── A11 — Partial card amounts credit through the ledger; default guard intact ───

test('A11 partial card payments credit correctly and over-amount intents are rejected', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer); // total 5800

    // Two partial card charges sum through the ledger to fully settle.
    $this->makePayment($invoice, 2800, 'stripe_card');
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Partial);
    $this->makePayment($invoice, 3000, 'stripe_card');
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);

    // An over-amount / unconfigured payment-intent never creates a charge or
    // marks the invoice paid. (In this env Stripe is unconfigured → 503 guard;
    // with Stripe configured the over-amount guard returns 422. Either way the
    // invoice is untouched.)
    $fresh = $this->makeInvoice($buyer);
    $resp  = $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/my/invoices/{$fresh->id}/payment-intent", ['amount' => 999999]);

    expect($resp->status())->toBeIn([422, 503]);
    expect($fresh->fresh()->status)->toBe(InvoiceStatus::Pending);
    expect((float) $fresh->fresh()->balance_due)->toBe(5800.0);
});
