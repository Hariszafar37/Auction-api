<?php

use App\Enums\InvoiceStatus;
use App\Models\User;
use App\Services\Payment\StripeService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Mail;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class, \Tests\Helpers\CreatesInvoiceData::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    config(['services.stripe.secret' => 'sk_test_dummy']);
    Mail::fake();
});

// ─── Forfeiture (admin Mark Defaulted) ────────────────────────────────────────

it('admin can default an overdue unpaid invoice — deposit forfeited, vehicle relisted', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer, [
        'due_at'                   => now()->subDay(),
        'deposit_status'           => 'captured',
        'stripe_deposit_intent_id' => 'pi_dep_kept',
        'status'                   => InvoiceStatus::Partial,
        'amount_paid'              => 300,
        'balance_due'              => 5500,
    ]);

    // Forfeiture keeps the money — no Stripe refund/cancel may be issued.
    $this->mock(StripeService::class, function ($mock) {
        $mock->shouldReceive('refund')->never();
        $mock->shouldReceive('cancelPaymentIntent')->never();
    });

    $admin = $this->makeAdmin();
    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/invoices/{$invoice->id}/default")
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'defaulted');

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Defaulted)
        ->and($invoice->fresh()->deposit_status)->toBe('captured')   // kept, not refunded
        ->and($invoice->fresh()->vehicle->status)->toBe('available'); // relisted
});

it('cannot default a paid invoice', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer, [
        'status'      => InvoiceStatus::Paid,
        'amount_paid' => 5800,
        'balance_due' => 0,
        'paid_at'     => now(),
        'due_at'      => now()->subDay(),
    ]);

    $this->actingAs($this->makeAdmin(), 'sanctum')
        ->postJson("/api/v1/admin/invoices/{$invoice->id}/default")
        ->assertStatus(422)
        ->assertJsonPath('code', 'invoice_paid');
});

it('cannot default an invoice before its due date', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer, ['due_at' => now()->addDays(3)]);

    $this->actingAs($this->makeAdmin(), 'sanctum')
        ->postJson("/api/v1/admin/invoices/{$invoice->id}/default")
        ->assertStatus(422)
        ->assertJsonPath('code', 'not_yet_due');
});

// ─── Refund on cancel (void) ──────────────────────────────────────────────────

it('voiding an invoice refunds a captured deposit', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer, [
        'deposit_status'           => 'captured',
        'stripe_deposit_intent_id' => 'pi_dep_refundme',
    ]);
    $this->makePayment($invoice, 300, 'deposit'); // completed deposit credit

    $this->mock(StripeService::class, function ($mock) {
        $mock->shouldReceive('refund')->once()->with('pi_dep_refundme')->andReturn(
            \Stripe\Refund::constructFrom(['id' => 're_test_1', 'status' => 'succeeded'])
        );
    });

    $this->actingAs($this->makeAdmin(), 'sanctum')
        ->postJson("/api/v1/admin/invoices/{$invoice->id}/void")
        ->assertStatus(200);

    expect($invoice->fresh()->status->value)->toBe('void')
        ->and($invoice->fresh()->deposit_status)->toBe('refunded');
});

// ─── Buyer deposit retry ──────────────────────────────────────────────────────

it('buyer can retry a failed deposit after fixing their card', function () {
    $buyer = User::factory()->create(['status' => 'active', 'stripe_customer_id' => 'cus_retry']);
    $buyer->billingInformation()->create([
        'billing_address'          => '1 St',
        'billing_country'          => 'US',
        'billing_city'             => 'Baltimore',
        'billing_zip_postal_code'  => '21201',
        'payment_method_added'     => true,
        'stripe_payment_method_id' => 'pm_retry_card',
        'card_brand'               => 'visa',
        'card_last_four'           => '4242',
        'card_expiry_month'        => 12,
        'card_expiry_year'         => (int) now()->year + 3,
    ]);

    $invoice = $this->makeInvoice($buyer, ['deposit_status' => 'failed']);

    $this->mock(StripeService::class, function ($mock) {
        $mock->shouldReceive('configured')->andReturnTrue();
        $mock->shouldReceive('chargeOffSession')->once()->andReturn(
            \Stripe\PaymentIntent::constructFrom(['id' => 'pi_retry_ok', 'status' => 'succeeded'])
        );
    });

    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/my/invoices/{$invoice->id}/retry-deposit")
        ->assertStatus(200);

    expect($invoice->fresh()->deposit_status)->toBe('captured')
        ->and((float) $invoice->fresh()->amount_paid)->toBe(300.0);
});

it('retry-deposit is rejected when no deposit retry is pending', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer, ['deposit_status' => 'captured']);

    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/my/invoices/{$invoice->id}/retry-deposit")
        ->assertStatus(422)
        ->assertJsonPath('code', 'deposit_not_retryable');
});
