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

// ─── recalculateBalance ───────────────────────────────────────────────────────

test('invoice remains unpaid after a partial payment', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer); // total = 5800

    InvoicePayment::create([
        'invoice_id'   => $invoice->id,
        'user_id'      => $buyer->id,
        'method'       => 'cash',
        'amount'       => 2000,
        'status'       => 'completed',
        'processed_at' => now(),
    ]);

    $invoice->recalculateBalance();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Partial)
        ->and((float) $invoice->fresh()->amount_paid)->toBe(2000.0)
        ->and((float) $invoice->fresh()->balance_due)->toBe(3800.0);
});

test('invoice is marked paid when remaining balance reaches zero', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer); // total = 5800

    // First payment
    InvoicePayment::create([
        'invoice_id'   => $invoice->id,
        'user_id'      => $buyer->id,
        'method'       => 'cash',
        'amount'       => 3000,
        'status'       => 'completed',
        'processed_at' => now(),
    ]);
    $invoice->recalculateBalance();
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Partial);

    // Second payment — completes the invoice
    InvoicePayment::create([
        'invoice_id'   => $invoice->id,
        'user_id'      => $buyer->id,
        'method'       => 'check',
        'amount'       => 2800,
        'status'       => 'completed',
        'processed_at' => now(),
    ]);
    $invoice->recalculateBalance();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and((float) $invoice->fresh()->balance_due)->toBe(0.0)
        ->and($invoice->fresh()->paid_at)->not->toBeNull();
});

test('remaining balance accessor reflects live arithmetic', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer); // total = 5800, paid = 0

    expect($invoice->remaining_balance)->toBe(5800.0);

    $invoice->amount_paid = 2000;
    expect($invoice->remaining_balance)->toBe(3800.0);
});

test('amount paid sums all completed payments not just the latest', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer); // total = 5800

    foreach ([1000, 2000, 2800] as $amount) {
        InvoicePayment::create([
            'invoice_id'   => $invoice->id,
            'user_id'      => $buyer->id,
            'method'       => 'cash',
            'amount'       => $amount,
            'status'       => 'completed',
            'processed_at' => now(),
        ]);
    }

    $invoice->recalculateBalance();

    expect((float) $invoice->fresh()->amount_paid)->toBe(5800.0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

test('pending verification payments do not count toward amount paid', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    InvoicePayment::create([
        'invoice_id' => $invoice->id,
        'user_id'    => $buyer->id,
        'method'     => 'cash',
        'amount'     => 5800,
        'status'     => 'pending_verification', // not yet approved
    ]);

    $invoice->recalculateBalance();

    // Status should not change — pending_verification is not counted
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Pending)
        ->and((float) $invoice->fresh()->amount_paid)->toBe(0.0);
});

test('multiple payment methods can settle a single invoice', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer); // total = 5800

    $payments = [
        ['method' => 'cash',        'amount' => 2000],
        ['method' => 'check',       'amount' => 2000],
        ['method' => 'stripe_card', 'amount' => 1800],
    ];

    foreach ($payments as $p) {
        InvoicePayment::create([
            'invoice_id'   => $invoice->id,
            'user_id'      => $buyer->id,
            'method'       => $p['method'],
            'amount'       => $p['amount'],
            'status'       => 'completed',
            'processed_at' => now(),
        ]);
    }

    $invoice->recalculateBalance();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(InvoicePayment::where('invoice_id', $invoice->id)->where('status', 'completed')->count())->toBe(3);
});

// ─── Invoice resource ─────────────────────────────────────────────────────────

test('invoice resource exposes remaining balance to buyer', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer, [
        'total_amount' => 5800,
        'amount_paid'  => 2000,
        'balance_due'  => 3800,
        'status'       => InvoiceStatus::Partial,
    ]);

    $this->actingAs($buyer, 'sanctum')
         ->getJson("/api/v1/my/invoices/{$invoice->id}")
         ->assertOk()
         ->assertJsonPath('data.remaining_balance', 3800)
         ->assertJsonPath('data.amount_paid', 2000);
});
