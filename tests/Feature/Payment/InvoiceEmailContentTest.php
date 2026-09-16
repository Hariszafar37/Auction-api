<?php

use App\Enums\AuctionStatus;
use App\Enums\InvoiceStatus;
use App\Enums\LotStatus;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use App\Models\Vehicle;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Guards the buyer-facing money figures in the two invoice emails.
 *
 * The bug these cover: the "Invoice Due" email printed the gross total_amount,
 * so a buyer whose $300 deposit had already been captured was quoted the full
 * amount instead of their real remaining balance. The deposit was never
 * additive and the balance was already correct in the DB — only the template
 * failed to read it.
 */

// ─── Helpers ────────────────────────────────────────────────────────────────

function emailInvoice(array $overrides = []): Invoice
{
    $buyer   = User::factory()->create(['status' => 'active', 'name' => 'Test Buyer']);
    $creator = User::factory()->create(['status' => 'active']);

    $auction = Auction::create([
        'title'      => 'Email Content Auction',
        'location'   => 'Baltimore, MD',
        'starts_at'  => now()->subHour(),
        'status'     => AuctionStatus::Live,
        'created_by' => $creator->id,
    ]);

    $vehicle = Vehicle::create([
        'seller_id' => $creator->id,
        'vin'       => strtoupper(fake()->unique()->lexify('?????????????????')),
        'year'      => 2022,
        'make'      => 'Toyota',
        'model'     => 'Camry',
        'status'    => 'in_auction',
    ]);

    $lot = AuctionLot::create([
        'auction_id'               => $auction->id,
        'vehicle_id'               => $vehicle->id,
        'lot_number'               => 1,
        'status'                   => LotStatus::Sold,
        'starting_bid'             => 1000,
        'current_bid'              => 4450,
        'sold_price'               => 4450,
        'buyer_id'                 => $buyer->id,
        'current_winner_id'        => $buyer->id,
        'requires_seller_approval' => false,
    ]);

    // Client's scenario: a $5,000 total made of sale + fees, $50 of which is the
    // online platform fee that the emails previously never printed.
    return Invoice::create(array_merge([
        'invoice_number'             => 'INV-2026-09999',
        'lot_id'                     => $lot->id,
        'auction_id'                 => $auction->id,
        'buyer_id'                   => $buyer->id,
        'vehicle_id'                 => $vehicle->id,
        'sale_price'                 => 4450,
        'deposit_amount'             => 300.00,
        'buyer_fee_amount'           => 400.00,
        'tax_amount'                 => 100.00,
        'tags_amount'                => 0.00,
        'storage_days'               => 0,
        'storage_fee_amount'         => 0.00,
        'online_platform_fee_amount' => 50.00,
        'total_amount'               => 5000.00,
        'adjustments_total'          => 0.00,
        'amount_paid'                => 0.00,
        'balance_due'                => 5000.00,
        'status'                     => InvoiceStatus::Pending,
        'due_at'                     => now()->addDays(7),
    ], $overrides));
}

/** Capture the deposit the way InvoiceService::finalizeCapturedDeposit() does. */
function captureDepositOn(Invoice $invoice, float $amount = 300.00): Invoice
{
    InvoicePayment::create([
        'invoice_id'   => $invoice->id,
        'user_id'      => $invoice->buyer_id,
        'method'       => 'deposit',
        'amount'       => $amount,
        'reference'    => 'pi_deposit_email_test',
        'status'       => 'completed',
        'processed_at' => now(),
    ]);

    $invoice->update(['deposit_status' => 'captured', 'deposit_captured_at' => now()]);
    $invoice->recalculateBalance();

    return $invoice->fresh();
}

/** Flatten a rendered email's two-column rows into "Label|Value" strings. */
function emailRows(string $view, Invoice $invoice): array
{
    $html = view($view, ['invoice' => $invoice])->render();
    preg_match_all(
        '/<div class="row[^"]*">\s*<span>(.*?)<\/span>\s*<span>(.*?)<\/span>/s',
        $html,
        $m,
        PREG_SET_ORDER
    );

    return collect($m)
        ->map(fn ($r) => trim(strip_tags($r[1])) . '|' . trim(strip_tags($r[2])))
        ->all();
}

function hasRowLabel(array $rows, string $label): bool
{
    return collect($rows)->contains(fn ($r) => str_starts_with($r, $label . '|'));
}

// ─── Tests ──────────────────────────────────────────────────────────────────

dataset('invoice emails', ['emails.invoice-created', 'emails.invoice-overdue']);

it('subtracts the captured deposit and shows the real remaining balance', function (string $view) {
    $invoice = captureDepositOn(emailInvoice());

    // Precondition: the DB math was always right.
    expect((float) $invoice->amount_paid)->toBe(300.0)
        ->and((float) $invoice->balance_due)->toBe(4700.0);

    expect(emailRows($view, $invoice))
        ->toContain('Total|$5,000.00')
        ->toContain('Deposit Paid|-$300.00')
        ->toContain('Remaining Balance|$4,700.00');
})->with('invoice emails');

it('never quotes the gross total as the amount still owed', function (string $view) {
    $invoice = captureDepositOn(emailInvoice());
    $rows    = emailRows($view, $invoice);

    expect($rows)
        ->not->toContain('Total Due|$5,000.00')
        ->not->toContain('Total Owed|$5,000.00')
        ->not->toContain('Remaining Balance|$5,000.00');
})->with('invoice emails');

it('itemises the online platform fee so the rows reconcile with the total', function (string $view) {
    $invoice = captureDepositOn(emailInvoice());

    expect(emailRows($view, $invoice))->toContain('Online Platform Fee|$50.00');

    // Every fee line must add back up to the printed Total.
    $sum = (float) $invoice->sale_price
        + (float) $invoice->buyer_fee_amount
        + (float) $invoice->tax_amount
        + (float) $invoice->tags_amount
        + (float) $invoice->online_platform_fee_amount
        + (float) $invoice->storage_fee_amount;

    expect($sum)->toBe((float) $invoice->total_amount);
})->with('invoice emails');

it('shows no deposit credit when the deposit has not been captured', function (string $view) {
    // No card on file → deposit_status 'failed', nothing credited.
    $invoice = emailInvoice(['deposit_status' => 'failed']);
    $rows    = emailRows($view, $invoice);

    expect($rows)
        ->toContain('Total|$5,000.00')
        ->toContain('Remaining Balance|$5,000.00');

    expect(hasRowLabel($rows, 'Deposit Paid'))->toBeFalse();
})->with('invoice emails');

it('drops the deposit credit again when the deposit is refunded on void', function (string $view) {
    $invoice = captureDepositOn(emailInvoice());

    // Mirrors AdminInvoiceController::releaseDeposit().
    InvoicePayment::where('invoice_id', $invoice->id)
        ->where('method', 'deposit')
        ->update(['status' => 'refunded']);
    $invoice->update(['deposit_status' => 'refunded']);
    $invoice->recalculateBalance();
    $invoice = $invoice->fresh();

    $rows = emailRows($view, $invoice);

    expect(hasRowLabel($rows, 'Deposit Paid'))->toBeFalse();
    expect($rows)->toContain('Remaining Balance|$5,000.00');
})->with('invoice emails');

it('separates the deposit from other payments without double counting', function (string $view) {
    $invoice = captureDepositOn(emailInvoice());

    InvoicePayment::create([
        'invoice_id'   => $invoice->id,
        'user_id'      => $invoice->buyer_id,
        'method'       => 'wire',
        'amount'       => 1000.00,
        'status'       => 'verified',
        'processed_at' => now(),
    ]);
    $invoice->recalculateBalance();
    $invoice = $invoice->fresh();

    expect((float) $invoice->amount_paid)->toBe(1300.0);

    expect(emailRows($view, $invoice))
        ->toContain('Deposit Paid|-$300.00')
        ->toContain('Amount Paid|-$1,000.00')   // deposit excluded, not counted twice
        ->toContain('Remaining Balance|$3,700.00');
})->with('invoice emails');

it('keeps adjustments visible alongside the deposit credit', function (string $view) {
    $invoice = captureDepositOn(emailInvoice());

    InvoicePayment::create([
        'invoice_id'       => $invoice->id,
        'user_id'          => $invoice->buyer_id,
        'method'           => 'other',
        'transaction_type' => 'adjustment',
        'fee_type'         => 'Late Payment Fee',
        'amount'           => 50.00,
        'status'           => 'verified',
        'processed_at'     => now(),
    ]);
    $invoice->recalculateBalance();
    $invoice = $invoice->fresh();

    // 5000 + 50 late fee − 300 deposit = 4750
    expect(emailRows($view, $invoice))
        ->toContain('Adjustment / Fee|+$50.00')
        ->toContain('Deposit Paid|-$300.00')
        ->toContain('Remaining Balance|$4,750.00');
})->with('invoice emails');

it('depositCredited and otherPaymentsCredited always sum to amount_paid', function () {
    $invoice = captureDepositOn(emailInvoice());

    InvoicePayment::create([
        'invoice_id'   => $invoice->id,
        'user_id'      => $invoice->buyer_id,
        'method'       => 'cash',
        'amount'       => 250.00,
        'status'       => 'verified',
        'processed_at' => now(),
    ]);
    $invoice->recalculateBalance();
    $invoice = $invoice->fresh();

    expect($invoice->depositCredited())->toBe(300.0)
        ->and($invoice->otherPaymentsCredited())->toBe(250.0)
        ->and($invoice->depositCredited() + $invoice->otherPaymentsCredited())
            ->toBe((float) $invoice->amount_paid);
});
