<?php

use App\Enums\InvoiceStatus;
use App\Enums\LotStatus;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Vehicle;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\RolePermissionSeeder;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// The canonical brand logo file every surface (headers, emails, PDFs) resolves to.
const LOGO_PATH = 'images/colonial-logo.png';

// ─── Asset integrity ─────────────────────────────────────────────────────────
// Guards against the logo being deleted, corrupted, or replaced with a tiny /
// low-resolution image that would pixelate when rendered.

test('brand logo asset exists and is a valid, sufficiently large PNG', function () {
    $file = public_path(LOGO_PATH);

    expect(file_exists($file))->toBeTrue('Logo file is missing: ' . $file);

    $bytes = file_get_contents($file);
    // PNG magic number.
    expect(substr($bytes, 0, 8))->toBe("\x89PNG\r\n\x1a\n");

    [$width, $height] = getimagesize($file);
    // Displayed at up to 170px wide (emails); require headroom so it never upscales.
    expect($width)->toBeGreaterThanOrEqual(300)
        ->and($height)->toBeGreaterThanOrEqual(150);
});

// ─── Template references ─────────────────────────────────────────────────────
// Every logo-bearing template must point at the canonical path — catches drift
// (a renamed file, a stale path) before it ships a broken image to a client.

test('all logo-bearing templates reference the canonical logo path', function () {
    $templates = [
        'invoices/pdf.blade.php',
        'purchases/gate-pass.blade.php',
        'reports/admin-summary.blade.php',
        'emails/auction-won.blade.php',
        'emails/contact-inquiry.blade.php',
        'emails/deposit-action-required.blade.php',
        'emails/deposit-expiry-alert.blade.php',
        'emails/if-sale-notification.blade.php',
        'emails/invoice-created.blade.php',
        'emails/invoice-overdue.blade.php',
        'emails/payment-received.blade.php',
        'emails/pickup-ready.blade.php',
        'emails/transport-quote-received.blade.php',
    ];

    foreach ($templates as $tpl) {
        $contents = file_get_contents(resource_path('views/' . $tpl));
        expect($contents)->toContain(LOGO_PATH);
    }
});

// The markdown mail header is not in the list above because it resolves the
// logo through App\Support\Brand rather than hard-coding the path. Assert the
// helper lands on the same canonical file, so the two mechanisms cannot drift.

test('markdown mail header resolves the canonical logo path', function () {
    expect(\App\Support\Brand::LOGO_PATH)->toBe(LOGO_PATH)
        ->and(\App\Support\Brand::logoUrl())->toEndWith(LOGO_PATH);

    $header = file_get_contents(resource_path('views/vendor/mail/html/header.blade.php'));
    expect($header)->toContain('Brand::logoUrl()');
});

// ─── Live render ─────────────────────────────────────────────────────────────
// Proves the invoice PDF actually renders and embeds the current logo bytes.
// The gate pass and admin summary use the identical base64 embed line, so this
// exercises the shared mechanism end-to-end.

test('invoice PDF renders and embeds the current brand logo', function () {
    $this->seed(RolePermissionSeeder::class);

    $buyer   = User::factory()->create(['name' => 'Test Buyer', 'status' => 'active']);
    $creator = User::factory()->create(['status' => 'active']);

    $auction = Auction::create([
        'title'      => 'Logo Render Auction',
        'location'   => 'Baltimore, MD',
        'starts_at'  => now()->subHour(),
        'status'     => 'live',
        'created_by' => $creator->id,
    ]);
    $vehicle = Vehicle::create([
        'seller_id' => $creator->id,
        'vin'       => strtoupper(fake()->unique()->lexify('?????????????????')),
        'year'      => 2022,
        'make'      => 'Honda',
        'model'     => 'Accord',
        'status'    => 'in_auction',
    ]);
    $lot = AuctionLot::create([
        'auction_id'               => $auction->id,
        'vehicle_id'               => $vehicle->id,
        'lot_number'               => 1,
        'status'                   => LotStatus::Countdown,
        'starting_bid'             => 500,
        'current_bid'              => 5000,
        'sold_price'               => 5000,
        'buyer_id'                 => $buyer->id,
        'current_winner_id'        => $buyer->id,
        'requires_seller_approval' => false,
    ]);
    $invoice = Invoice::create([
        'invoice_number'     => 'INV-LOGO-001',
        'lot_id'             => $lot->id,
        'auction_id'         => $auction->id,
        'buyer_id'           => $buyer->id,
        'vehicle_id'         => $vehicle->id,
        'sale_price'         => 5000,
        'deposit_amount'     => 300,
        'buyer_fee_amount'   => 400,
        'tax_amount'         => 0,
        'tags_amount'        => 0,
        'storage_days'       => 0,
        'storage_fee_amount' => 0,
        'total_amount'       => 5400,
        'amount_paid'        => 0,
        'balance_due'        => 5400,
        'status'             => InvoiceStatus::Pending,
        'due_at'             => now()->addDays(7),
    ])->load(['buyer', 'auction', 'lot', 'vehicle', 'payments']);

    // HTML carries the same inline base64 the PDF embeds — assert the actual
    // current logo bytes are present, then confirm a real PDF is produced.
    $html   = view('invoices.pdf', ['invoice' => $invoice])->render();
    $logoB64 = base64_encode(file_get_contents(public_path(LOGO_PATH)));

    expect($html)->toContain('data:image/png;base64,' . substr($logoB64, 0, 128));

    $pdf = Pdf::loadView('invoices.pdf', ['invoice' => $invoice])->output();
    expect(substr($pdf, 0, 4))->toBe('%PDF')
        ->and(strlen($pdf))->toBeGreaterThan(20000);
});
