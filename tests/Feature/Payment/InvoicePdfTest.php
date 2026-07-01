<?php

use App\Models\User;
use App\Services\Payment\PaymentLedgerService;
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

// ─── Adjustment line in the totals box ──────────────────────────────────────────

test('pdf totals show the adjustment and TOTAL DUE reflects the effective total', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer, ['total_amount' => 1179, 'balance_due' => 1179]);
    $admin   = $this->makeAdmin();

    app(PaymentLedgerService::class)->applyAdjustment($invoice, 50, 'Applied after deadline', $admin, 'Late Payment Fee');

    $invoice->refresh()->load(['lot', 'auction', 'vehicle', 'buyer', 'payments']);

    $html = view('invoices.pdf', ['invoice' => $invoice])->render();

    // The adjustment is itemised in the totals box (not just in payment history)…
    expect($html)->toContain('Adjustment / Fee')
        ->and($html)->toContain('+$50.00')
        // …and TOTAL DUE now equals the effective total ($1,229), reconciling with BALANCE DUE.
        ->and($html)->toContain('$1,229.00');
});

test('pdf totals omit the adjustment line when there are none', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    $invoice->load(['lot', 'auction', 'vehicle', 'buyer', 'payments']);

    $html = view('invoices.pdf', ['invoice' => $invoice])->render();

    expect($html)->not->toContain('Adjustment / Fee')
        ->and($html)->not->toContain('Adjustment / Credit');
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

    // Advance the clock so the update lands in a different second — otherwise a
    // fast CI run creates and updates the invoice within the same second and the
    // updated_at timestamp (and therefore the cache key) would not change.
    $this->travel(5)->minutes();

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
