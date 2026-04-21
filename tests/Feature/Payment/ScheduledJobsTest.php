<?php

use App\Enums\InvoiceStatus;
use App\Mail\InvoiceOverdue;
use App\Models\Invoice;
use App\Models\User;
use Database\Seeders\FeeConfigurationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Mail;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class, \Tests\Helpers\CreatesInvoiceData::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(FeeConfigurationSeeder::class);
    Mail::fake();
});

// ─── AccrueStorageFees ────────────────────────────────────────────────────────

test('accrue storage fees increments open invoice storage days and total', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer, [
        'storage_days'       => 2,
        'storage_fee_amount' => 50.00,
        'total_amount'       => 5850.00,
        'balance_due'        => 5850.00,
    ]);

    $this->artisan('invoices:accrue-storage')->assertSuccessful();

    $fresh = $invoice->fresh();
    expect($fresh->storage_days)->toBe(3) // 2 + 1
        ->and((float) $fresh->storage_fee_amount)->toBe(75.0); // 3 days × $25
});

test('accrue storage fees does not double accrue on same day', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);
    // Set storage_last_accrued_at to today so the command skips it
    $invoice->update(['storage_last_accrued_at' => now()->startOfDay()]);

    $this->artisan('invoices:accrue-storage')->assertSuccessful();

    expect($invoice->fresh()->storage_days)->toBe(0); // unchanged
});

test('accrue storage fees skips paid invoices', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer, [
        'status'      => InvoiceStatus::Paid,
        'amount_paid' => 5800,
        'balance_due' => 0,
        'paid_at'     => now(),
    ]);

    $this->artisan('invoices:accrue-storage')->assertSuccessful();

    expect($invoice->fresh()->storage_days)->toBe(0);
});

test('accrue storage fees skips void invoices', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer, [
        'status'    => InvoiceStatus::Void,
        'voided_at' => now(),
    ]);

    $this->artisan('invoices:accrue-storage')->assertSuccessful();

    expect($invoice->fresh()->storage_days)->toBe(0);
});

test('accrue storage fees processes partial invoices', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer, [
        'status'      => InvoiceStatus::Partial,
        'amount_paid' => 2000,
        'balance_due' => 3800,
    ]);

    $this->artisan('invoices:accrue-storage')->assertSuccessful();

    expect($invoice->fresh()->storage_days)->toBe(1);
});

test('accrue storage fees processes overdue invoices', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer, [
        'status' => InvoiceStatus::Overdue,
        'due_at' => now()->subDay(),
    ]);

    $this->artisan('invoices:accrue-storage')->assertSuccessful();

    expect($invoice->fresh()->storage_days)->toBe(1);
});

// ─── MarkOverdueInvoices ──────────────────────────────────────────────────────

test('mark overdue invoices sets status to overdue for past due invoices', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer, ['due_at' => now()->subDay()]);

    $this->artisan('invoices:mark-overdue')->assertSuccessful();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Overdue);
});

test('mark overdue invoices queues notification email', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer, ['due_at' => now()->subDay()]);

    $this->artisan('invoices:mark-overdue')->assertSuccessful();

    Mail::assertQueued(InvoiceOverdue::class, fn ($mail) =>
        $mail->hasTo($buyer->email)
    );
});

test('mark overdue invoices only sends notification once', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer, ['due_at' => now()->subDay()]);

    $this->artisan('invoices:mark-overdue')->assertSuccessful();
    $this->artisan('invoices:mark-overdue')->assertSuccessful();

    // Email queued exactly once — overdue_notified_at guard prevented second send
    Mail::assertQueued(InvoiceOverdue::class, 1);
});

test('mark overdue invoices does not resend if overdue notified at is set', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer, [
        'status'              => InvoiceStatus::Overdue,
        'due_at'              => now()->subDay(),
        'overdue_notified_at' => now()->subHour(), // already notified
    ]);

    $this->artisan('invoices:mark-overdue')->assertSuccessful();

    Mail::assertNothingQueued();
});

test('mark overdue invoices does not affect paid invoices', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer, [
        'status'      => InvoiceStatus::Paid,
        'due_at'      => now()->subDay(),
        'amount_paid' => 5800,
        'balance_due' => 0,
        'paid_at'     => now(),
    ]);

    $this->artisan('invoices:mark-overdue')->assertSuccessful();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
    Mail::assertNothingQueued();
});

test('mark overdue invoices does not affect future due invoices', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer, ['due_at' => now()->addDays(3)]);

    $this->artisan('invoices:mark-overdue')->assertSuccessful();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Pending);
    Mail::assertNothingQueued();
});
