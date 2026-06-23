<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Cache;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class, \Tests\Helpers\CreatesInvoiceData::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

// ─── Online platform fee line item (template) ───────────────────────────────────

test('pdf template renders the online platform fee line item when charged', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer, [
        'online_platform_fee_amount' => 125.00,
        'total_amount'               => 5925,
        'balance_due'                => 5925,
    ]);

    $invoice->load(['lot', 'auction', 'vehicle', 'buyer', 'payments']);

    $html = view('invoices.pdf', ['invoice' => $invoice])->render();

    expect($html)->toContain('Online Platform Fee')
        ->and($html)->toContain('$125.00');
});

test('pdf template omits the online platform fee line when not charged', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer, ['online_platform_fee_amount' => 0]);

    $invoice->load(['lot', 'auction', 'vehicle', 'buyer', 'payments']);

    $html = view('invoices.pdf', ['invoice' => $invoice])->render();

    expect($html)->not->toContain('Online Platform Fee');
});

test('pdf template renders the live due date', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer, ['due_at' => now()->setDate(2026, 6, 30)]);

    $invoice->load(['lot', 'auction', 'vehicle', 'buyer', 'payments']);

    $html = view('invoices.pdf', ['invoice' => $invoice])->render();

    expect($html)->toContain('Jun 30, 2026');
});

// ─── PDF cache freshness (endpoint) ──────────────────────────────────────────────

test('pdf cache key reflects the invoice updated_at so a changed due date is not stale', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer, ['due_at' => now()->setDate(2026, 6, 12)]);

    $firstKey = 'invoice_pdf_' . $invoice->id . '_' . $invoice->updated_at->timestamp;

    $this->actingAs($buyer, 'sanctum')
         ->get("/api/v1/my/invoices/{$invoice->id}/pdf")
         ->assertOk()
         ->assertHeader('Content-Type', 'application/pdf');

    expect(Cache::has($firstKey))->toBeTrue();

    // The non-card deadline (and other flows) rewrite due_at, bumping updated_at.
    $invoice->update(['due_at' => now()->setDate(2026, 6, 30)]);
    $invoice->refresh();

    $secondKey = 'invoice_pdf_' . $invoice->id . '_' . $invoice->updated_at->timestamp;

    expect($secondKey)->not->toBe($firstKey);

    $this->actingAs($buyer, 'sanctum')
         ->get("/api/v1/my/invoices/{$invoice->id}/pdf")
         ->assertOk();

    // A fresh PDF is generated under the new key rather than serving the stale copy.
    expect(Cache::has($secondKey))->toBeTrue();
});
