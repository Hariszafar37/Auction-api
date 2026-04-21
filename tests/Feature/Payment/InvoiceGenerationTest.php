<?php

use App\Enums\InvoiceStatus;
use App\Enums\LotStatus;
use App\Events\Auction\UserWonLot;
use App\Listeners\Payment\CreateInvoiceForWonLot;
use App\Mail\InvoiceCreated;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Payment\InvoiceService;
use Database\Seeders\FeeConfigurationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Mail;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class, \Tests\Helpers\CreatesInvoiceData::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(FeeConfigurationSeeder::class);
    Mail::fake();
});

// ─── Helpers ──────────────────────────────────────────────────────────────────

function makeWonLot(int $soldPrice = 5000): array
{
    $buyer   = User::factory()->create(['status' => 'active']);
    $creator = User::factory()->create(['status' => 'active']);

    $auction = Auction::create([
        'title'      => 'Won Lot Auction',
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
        'model'     => 'Civic',
        'status'    => 'in_auction',
    ]);

    $lot = AuctionLot::create([
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

    $lot->load(['auction', 'vehicle', 'buyer']);

    return [$lot, $buyer];
}

// ─── Invoice creation ─────────────────────────────────────────────────────────

test('invoice created on user won lot event via listener', function () {
    [$lot, $buyer] = makeWonLot(5000);

    $listener = new CreateInvoiceForWonLot(app(InvoiceService::class));
    $listener->handle(new UserWonLot($lot));

    $invoice = Invoice::where('lot_id', $lot->id)->first();

    expect($invoice)->not->toBeNull()
        ->and($invoice->buyer_id)->toBe($buyer->id)
        ->and($invoice->sale_price)->toBe(5000)
        ->and($invoice->status)->toBe(InvoiceStatus::Pending);
});

test('invoice not duplicated on repeated user won lot event', function () {
    [$lot] = makeWonLot(5000);
    $service  = app(InvoiceService::class);
    $listener = new CreateInvoiceForWonLot($service);
    $event    = new UserWonLot($lot);

    $listener->handle($event);
    $listener->handle($event); // fire twice
    $listener->handle($event); // fire three times

    expect(Invoice::where('lot_id', $lot->id)->count())->toBe(1);
});

test('invoice contains all fee components', function () {
    [$lot] = makeWonLot(5000);

    app(InvoiceService::class)->createForLot($lot);

    $invoice = Invoice::where('lot_id', $lot->id)->first();

    expect($invoice->buyer_fee_amount)->toBeGreaterThan(0)
        ->and($invoice->tax_amount)->toBeGreaterThan(0)
        ->and($invoice->tags_amount)->toBeGreaterThan(0)
        ->and($invoice->deposit_amount)->toBeGreaterThan(0);
});

test('invoice total equals sum of all fee components', function () {
    [$lot] = makeWonLot(5000);

    app(InvoiceService::class)->createForLot($lot);

    $invoice = Invoice::where('lot_id', $lot->id)->first();

    $expected = $invoice->sale_price
        + (float) $invoice->buyer_fee_amount
        + (float) $invoice->tax_amount
        + (float) $invoice->tags_amount
        + (float) $invoice->storage_fee_amount;

    expect((float) $invoice->total_amount)->toBe($expected);
});

test('invoice due at is set to seven days from creation', function () {
    [$lot] = makeWonLot(5000);

    $before  = now()->addDays(7)->startOfSecond();
    app(InvoiceService::class)->createForLot($lot);
    $after   = now()->addDays(7)->addSecond();

    $invoice = Invoice::where('lot_id', $lot->id)->first();

    expect($invoice->due_at)->not->toBeNull()
        ->and($invoice->due_at->greaterThanOrEqualTo($before))->toBeTrue()
        ->and($invoice->due_at->lessThanOrEqualTo($after))->toBeTrue();
});

test('invoice created email is queued on invoice creation', function () {
    [$lot, $buyer] = makeWonLot(5000);

    app(InvoiceService::class)->createForLot($lot);

    Mail::assertQueued(InvoiceCreated::class, fn ($mail) =>
        $mail->hasTo($buyer->email)
    );
});

test('fee snapshot is stored on invoice at creation time', function () {
    [$lot] = makeWonLot(5000);

    app(InvoiceService::class)->createForLot($lot);

    $invoice = Invoice::where('lot_id', $lot->id)->first();

    expect($invoice->fee_snapshot)->toBeArray()
        ->and($invoice->fee_snapshot)->toHaveKey('buyer_fee');
});

// ─── Authorization ────────────────────────────────────────────────────────────

test('buyer cannot access another buyers invoice', function () {
    $buyer1 = User::factory()->create(['status' => 'active']);
    $buyer2 = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer1);

    $this->actingAs($buyer2, 'sanctum')
         ->getJson("/api/v1/my/invoices/{$invoice->id}")
         ->assertStatus(404);
});

test('buyer can access their own invoice', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    $this->actingAs($buyer, 'sanctum')
         ->getJson("/api/v1/my/invoices/{$invoice->id}")
         ->assertOk()
         ->assertJsonPath('data.id', $invoice->id);
});

test('unauthenticated user cannot access invoice', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);

    $this->getJson("/api/v1/my/invoices/{$invoice->id}")
         ->assertUnauthorized();
});
