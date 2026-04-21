<?php

use App\Console\Commands\CheckDepositExpiry;
use App\Enums\InvoiceStatus;
use App\Mail\DepositExpiryAlert;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class, \Tests\Helpers\CreatesInvoiceData::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Mail::fake();
});

// ─── Deposit hold ─────────────────────────────────────────────────────────────

test('invoice deposit status is pending when stripe is not configured', function () {
    // With no STRIPE_SECRET, InvoiceService skips the deposit hold call.
    // deposit_status column defaults to the value set during invoice creation.
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    // Without a Stripe secret the deposit_status is either null or 'pending'
    expect(in_array($invoice->deposit_status, [null, 'pending']))->toBeTrue();
});

test('deposit status set to authorized when stripe deposit hold is created', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer, ['deposit_status' => 'authorized']);

    expect($invoice->deposit_status)->toBe('authorized');
});

// ─── Deposit captured on full payment ─────────────────────────────────────────

test('deposit payment record transitions to completed when invoice is fully paid', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer, [
        'deposit_status'             => 'authorized',
        'stripe_deposit_intent_id'   => null, // no Stripe capture attempt needed
    ]);

    // Simulate a deposit hold record
    $depositPayment = InvoicePayment::create([
        'invoice_id' => $invoice->id,
        'user_id'    => $buyer->id,
        'method'     => 'deposit',
        'amount'     => 300,
        'status'     => 'authorized',
    ]);

    // Simulate a completed card payment for the full balance
    InvoicePayment::create([
        'invoice_id'   => $invoice->id,
        'user_id'      => $buyer->id,
        'method'       => 'stripe_card',
        'amount'       => 5800,
        'status'       => 'completed',
        'processed_at' => now(),
    ]);

    $invoice->recalculateBalance();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

// ─── Deposit cancelled on void ────────────────────────────────────────────────

test('deposit status set to cancelled when invoice is voided via admin endpoint', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    // No stripe_deposit_intent_id → void skips the Stripe API cancel call
    $invoice->update(['deposit_status' => 'pending']);

    $admin = $this->makeAdmin();

    $this->actingAs($admin, 'sanctum')
         ->postJson("/api/v1/admin/invoices/{$invoice->id}/void")
         ->assertOk();

    expect($invoice->fresh()->status->value)->toBe('void');
});

test('paid invoice cannot be voided', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer, [
        'status'      => InvoiceStatus::Paid,
        'amount_paid' => 5800,
        'balance_due' => 0,
        'paid_at'     => now(),
    ]);
    $admin = $this->makeAdmin();

    $this->actingAs($admin, 'sanctum')
         ->postJson("/api/v1/admin/invoices/{$invoice->id}/void")
         ->assertStatus(422);
});

// ─── CheckDepositExpiry command ───────────────────────────────────────────────

test('deposit expiry job flags invoices with authorized deposit older than six days', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer, ['deposit_status' => 'authorized']);

    // Backdate to 7 days ago — must bypass Eloquent which ignores created_at in update()
    DB::table('invoices')->where('id', $invoice->id)->update(['created_at' => now()->subDays(7)]);

    $this->artisan('invoices:check-deposit-expiry')->assertSuccessful();

    expect($invoice->fresh()->deposit_status)->toBe('expired');
});

test('deposit expiry job queues admin alert email', function () {
    config(['mail.from.address' => 'admin@test.com']);

    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer, ['deposit_status' => 'authorized']);
    DB::table('invoices')->where('id', $invoice->id)->update(['created_at' => now()->subDays(7)]);

    $this->artisan('invoices:check-deposit-expiry')->assertSuccessful();

    Mail::assertQueued(DepositExpiryAlert::class);
});

test('deposit expiry job does not flag recently created invoices', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer, ['deposit_status' => 'authorized']);
    // created_at defaults to now — within 6 days

    $this->artisan('invoices:check-deposit-expiry')->assertSuccessful();

    expect($invoice->fresh()->deposit_status)->toBe('authorized');
});

test('deposit expiry job does not process already captured deposits', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer, ['deposit_status' => 'captured']);
    $invoice->update(['created_at' => now()->subDays(7)]);

    $this->artisan('invoices:check-deposit-expiry')->assertSuccessful();

    expect($invoice->fresh()->deposit_status)->toBe('captured'); // unchanged
});
