<?php

use App\Enums\AuctionStatus;
use App\Enums\LotStatus;
use App\Mail\DepositActionRequired;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\FeeConfiguration;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Payment\InvoiceService;
use App\Services\Payment\StripeService;
use Illuminate\Support\Facades\Mail;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    config(['services.stripe.secret' => 'sk_test_dummy']);
    Mail::fake();

    // $300 flat deposit (flat_range → min_amount), matching production config.
    FeeConfiguration::create([
        'fee_type'         => 'deposit',
        'label'            => 'Deposit',
        'calculation_type' => 'flat_range',
        'amount'           => null,
        'min_amount'       => 300.00,
        'max_amount'       => 500.00,
        'applies_to'       => 'buyer',
        'is_active'        => true,
        'sort_order'       => 1,
    ]);
});

// ─── Helpers ────────────────────────────────────────────────────────────────

function depositBuyerWithCard(bool $withCard = true): User
{
    $buyer = User::factory()->create([
        'status'             => 'active',
        'stripe_customer_id' => $withCard ? 'cus_test_buyer' : null,
    ]);

    $buyer->billingInformation()->create([
        'billing_address'          => '1 St',
        'billing_country'          => 'US',
        'billing_city'             => 'Baltimore',
        'billing_zip_postal_code'  => '21201',
        'payment_method_added'     => $withCard,
        'stripe_payment_method_id' => $withCard ? 'pm_test_buyer_card' : null,
        'card_brand'               => 'visa',
        'card_last_four'           => '4242',
        'card_expiry_month'        => 12,
        'card_expiry_year'         => (int) now()->year + 3,
    ]);

    return $buyer;
}

function depositWonLot(User $buyer, int $soldPrice = 5000): AuctionLot
{
    $creator = User::factory()->create(['status' => 'active']);

    $auction = Auction::create([
        'title'      => 'Deposit Test Auction',
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

    return AuctionLot::create([
        'auction_id'               => $auction->id,
        'vehicle_id'               => $vehicle->id,
        'lot_number'               => 1,
        'status'                   => LotStatus::Countdown,
        'starting_bid'             => 1000,
        'current_bid'              => $soldPrice,
        'sold_price'               => $soldPrice,
        'buyer_id'                 => $buyer->id,
        'current_winner_id'        => $buyer->id,
        'requires_seller_approval' => false,
    ]);
}

function depositSucceededPi(string $id = 'pi_deposit_123'): \Stripe\PaymentIntent
{
    return \Stripe\PaymentIntent::constructFrom(['id' => $id, 'status' => 'succeeded']);
}

// ─── Tests ──────────────────────────────────────────────────────────────────

it('charges the deposit off-session on win and credits it to the invoice', function () {
    $buyer = depositBuyerWithCard();
    $lot   = depositWonLot($buyer);

    $this->mock(StripeService::class, function ($mock) {
        $mock->shouldReceive('configured')->andReturnTrue();
        $mock->shouldReceive('chargeOffSession')
            ->once()
            ->andReturn(depositSucceededPi());
    });

    $invoice = app(InvoiceService::class)->createForLot($lot)->fresh();

    expect($invoice->deposit_status)->toBe('captured')
        ->and((float) $invoice->amount_paid)->toBe(300.0)
        ->and((float) $invoice->balance_due)->toBe((float) $invoice->total_amount - 300.0)
        ->and($invoice->deposit_captured_at)->not->toBeNull();

    $deposit = $invoice->payments()->where('method', 'deposit')->first();
    expect($deposit->status)->toBe('completed')
        ->and((float) $deposit->amount)->toBe(300.0)
        ->and($deposit->reference)->toBe('pi_deposit_123');
});

it('total never includes the deposit (no double charge)', function () {
    $buyer = depositBuyerWithCard();
    $lot   = depositWonLot($buyer, 5000);

    $this->mock(StripeService::class, function ($mock) {
        $mock->shouldReceive('configured')->andReturnTrue();
        $mock->shouldReceive('chargeOffSession')->andReturn(depositSucceededPi());
    });

    $invoice = app(InvoiceService::class)->createForLot($lot)->fresh();

    // total = sale_price + fees, NOT + deposit. Collected = balance + deposit = total.
    expect((float) $invoice->total_amount)->toBe(5000.0)
        ->and((float) $invoice->balance_due + 300.0)->toBe((float) $invoice->total_amount);
});

it('is idempotent — re-running createForLot does not charge twice', function () {
    $buyer = depositBuyerWithCard();
    $lot   = depositWonLot($buyer);

    $this->mock(StripeService::class, function ($mock) {
        $mock->shouldReceive('configured')->andReturnTrue();
        // Must be called exactly once across both createForLot invocations.
        $mock->shouldReceive('chargeOffSession')->once()->andReturn(depositSucceededPi());
    });

    $service = app(InvoiceService::class);
    $service->createForLot($lot);
    $service->createForLot($lot); // returns existing invoice, no second charge

    expect(\App\Models\Invoice::where('lot_id', $lot->id)->count())->toBe(1)
        ->and(\App\Models\InvoicePayment::where('method', 'deposit')->count())->toBe(1);
});

it('marks deposit failed and notifies the buyer when no card is on file', function () {
    $buyer = depositBuyerWithCard(withCard: false);
    $lot   = depositWonLot($buyer);

    $this->mock(StripeService::class, function ($mock) {
        $mock->shouldReceive('configured')->andReturnTrue();
        $mock->shouldReceive('chargeOffSession')->never();
    });

    $invoice = app(InvoiceService::class)->createForLot($lot)->fresh();

    expect($invoice->deposit_status)->toBe('failed')
        ->and((float) $invoice->amount_paid)->toBe(0.0)
        ->and((float) $invoice->balance_due)->toBe((float) $invoice->total_amount);

    Mail::assertQueued(DepositActionRequired::class, fn ($m) => $m->reason === 'no_payment_method');
});

it('marks deposit failed and notifies the buyer on a card decline', function () {
    $buyer = depositBuyerWithCard();
    $lot   = depositWonLot($buyer);

    $this->mock(StripeService::class, function ($mock) {
        $mock->shouldReceive('configured')->andReturnTrue();
        $mock->shouldReceive('chargeOffSession')->andThrow(
            \Stripe\Exception\CardException::factory(
                message: 'Your card was declined.',
                httpStatus: 402,
                jsonBody: ['error' => ['code' => 'card_declined', 'message' => 'Your card was declined.']],
                stripeCode: 'card_declined',
            )
        );
    });

    $invoice = app(InvoiceService::class)->createForLot($lot)->fresh();

    expect($invoice->deposit_status)->toBe('failed');
    Mail::assertQueued(DepositActionRequired::class, fn ($m) => $m->reason === 'declined');
});

it('marks deposit requires_action and stores the client secret when SCA is required', function () {
    $buyer = depositBuyerWithCard();
    $lot   = depositWonLot($buyer);

    $this->mock(StripeService::class, function ($mock) {
        $mock->shouldReceive('configured')->andReturnTrue();
        $mock->shouldReceive('chargeOffSession')->andThrow(
            \Stripe\Exception\CardException::factory(
                message: 'Authentication required',
                httpStatus: 402,
                jsonBody: ['error' => [
                    'code'           => 'authentication_required',
                    'message'        => 'Authentication required',
                    'payment_intent' => ['id' => 'pi_sca_123', 'client_secret' => 'pi_sca_123_secret'],
                ]],
                stripeCode: 'authentication_required',
            )
        );
    });

    $invoice = app(InvoiceService::class)->createForLot($lot)->fresh();

    expect($invoice->deposit_status)->toBe('requires_action')
        ->and($invoice->stripe_deposit_intent_id)->toBe('pi_sca_123');

    $deposit = $invoice->payments()->where('method', 'deposit')->first();
    expect($deposit->status)->toBe('requires_action')
        ->and($deposit->getAttribute('stripe_client_secret'))->toBe('pi_sca_123_secret');

    Mail::assertQueued(DepositActionRequired::class, fn ($m) => $m->reason === 'requires_action');
});

it('retryDeposit captures a previously failed deposit', function () {
    $buyer = depositBuyerWithCard();
    $lot   = depositWonLot($buyer);

    // First: decline on win.
    $this->mock(StripeService::class, function ($mock) {
        $mock->shouldReceive('configured')->andReturnTrue();
        $mock->shouldReceive('chargeOffSession')->once()->andThrow(
            \Stripe\Exception\CardException::factory(
                message: 'Declined', httpStatus: 402,
                jsonBody: ['error' => ['code' => 'card_declined']], stripeCode: 'card_declined',
            )
        );
    });
    $invoice = app(InvoiceService::class)->createForLot($lot)->fresh();
    expect($invoice->deposit_status)->toBe('failed');

    // Buyer fixes card → retry succeeds.
    $this->mock(StripeService::class, function ($mock) {
        $mock->shouldReceive('configured')->andReturnTrue();
        $mock->shouldReceive('chargeOffSession')->once()->andReturn(depositSucceededPi('pi_retry_ok'));
    });

    $ok = app(InvoiceService::class)->retryDeposit($invoice);

    expect($ok)->toBeTrue()
        ->and($invoice->fresh()->deposit_status)->toBe('captured')
        ->and((float) $invoice->fresh()->amount_paid)->toBe(300.0);
});
