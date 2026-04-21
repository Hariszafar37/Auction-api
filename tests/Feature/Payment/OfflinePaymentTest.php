<?php

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class, \Tests\Helpers\CreatesInvoiceData::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

// ─── Record offline payment ───────────────────────────────────────────────────

test('admin can record an offline cash payment', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);
    $admin   = $this->makeAdmin();

    $this->actingAs($admin, 'sanctum')
         ->postJson("/api/v1/admin/invoices/{$invoice->id}/record-payment", [
             'method' => 'cash',
             'amount' => 5800,
         ])
         ->assertCreated();

    expect(InvoicePayment::where('invoice_id', $invoice->id)->where('method', 'cash')->count())->toBe(1);
});

test('offline payment creates a pending verification record', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);
    $admin   = $this->makeAdmin();

    $this->actingAs($admin, 'sanctum')
         ->postJson("/api/v1/admin/invoices/{$invoice->id}/record-payment", [
             'method'    => 'check',
             'amount'    => 5800,
             'reference' => 'CHK-1042', // required for check method
         ])
         ->assertCreated();

    $payment = InvoicePayment::where('invoice_id', $invoice->id)->first();
    expect($payment->status)->toBe('pending_verification');
});

test('non admin cannot record an offline payment', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    $this->actingAs($buyer, 'sanctum')
         ->postJson("/api/v1/admin/invoices/{$invoice->id}/record-payment", [
             'method' => 'cash',
             'amount' => 5800,
         ])
         ->assertForbidden();
});

// ─── Approve offline payment ──────────────────────────────────────────────────

test('admin can approve an offline payment', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);
    $admin   = $this->makeAdmin();

    $payment = InvoicePayment::create([
        'invoice_id' => $invoice->id,
        'user_id'    => $buyer->id,
        'method'     => 'cash',
        'amount'     => 5800,
        'status'     => 'pending_verification',
    ]);

    $this->actingAs($admin, 'sanctum')
         ->postJson("/api/v1/admin/invoices/{$invoice->id}/payments/{$payment->id}/approve")
         ->assertOk();

    expect($payment->fresh()->status)->toBe('verified');
});

test('approval records approver id and timestamp', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);
    $admin   = $this->makeAdmin();

    $payment = InvoicePayment::create([
        'invoice_id' => $invoice->id,
        'user_id'    => $buyer->id,
        'method'     => 'cash',
        'amount'     => 5800,
        'status'     => 'pending_verification',
    ]);

    $this->actingAs($admin, 'sanctum')
         ->postJson("/api/v1/admin/invoices/{$invoice->id}/payments/{$payment->id}/approve", [
             'note' => 'Cash received at front desk',
         ])
         ->assertOk();

    $fresh = $payment->fresh();
    expect($fresh->approved_by)->toBe($admin->id)
        ->and($fresh->approved_at)->not->toBeNull()
        ->and($fresh->approval_note)->toBe('Cash received at front desk');
});

test('invoice is marked paid after offline approval when fully paid', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);
    $admin   = $this->makeAdmin();

    $payment = InvoicePayment::create([
        'invoice_id' => $invoice->id,
        'user_id'    => $buyer->id,
        'method'     => 'cash',
        'amount'     => 5800,
        'status'     => 'pending_verification',
    ]);

    $this->actingAs($admin, 'sanctum')
         ->postJson("/api/v1/admin/invoices/{$invoice->id}/payments/{$payment->id}/approve")
         ->assertOk();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->fresh()->paid_at)->not->toBeNull();
});

test('invoice stays open before offline payment is approved', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);
    $admin   = $this->makeAdmin();

    // Record payment but don't approve yet
    InvoicePayment::create([
        'invoice_id' => $invoice->id,
        'user_id'    => $buyer->id,
        'method'     => 'cash',
        'amount'     => 5800,
        'status'     => 'pending_verification',
    ]);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Pending);
});

// ─── Reject offline payment ───────────────────────────────────────────────────

test('admin can reject an offline payment', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);
    $admin   = $this->makeAdmin();

    $payment = InvoicePayment::create([
        'invoice_id' => $invoice->id,
        'user_id'    => $buyer->id,
        'method'     => 'check',
        'amount'     => 5800,
        'status'     => 'pending_verification',
    ]);

    $this->actingAs($admin, 'sanctum')
         ->postJson("/api/v1/admin/invoices/{$invoice->id}/payments/{$payment->id}/reject", [
             'reason' => 'Check bounced',
         ])
         ->assertOk();

    expect($payment->fresh()->status)->toBe('rejected');
});

test('invoice remains open after payment is rejected', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);
    $admin   = $this->makeAdmin();

    $payment = InvoicePayment::create([
        'invoice_id' => $invoice->id,
        'user_id'    => $buyer->id,
        'method'     => 'check',
        'amount'     => 5800,
        'status'     => 'pending_verification',
    ]);

    $this->actingAs($admin, 'sanctum')
         ->postJson("/api/v1/admin/invoices/{$invoice->id}/payments/{$payment->id}/reject")
         ->assertOk();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Pending);
});

test('cannot approve or reject a payment that belongs to a different invoice', function () {
    $buyer    = User::factory()->create(['status' => 'active']);
    $invoice1 = $this->makeInvoice($buyer);
    $invoice2 = $this->makeInvoice($buyer);
    $admin    = $this->makeAdmin();

    $payment = InvoicePayment::create([
        'invoice_id' => $invoice2->id,
        'user_id'    => $buyer->id,
        'method'     => 'cash',
        'amount'     => 5800,
        'status'     => 'pending_verification',
    ]);

    $this->actingAs($admin, 'sanctum')
         ->postJson("/api/v1/admin/invoices/{$invoice1->id}/payments/{$payment->id}/approve")
         ->assertStatus(422);
});
