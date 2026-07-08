<?php

use App\Enums\LotStatus;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use App\Services\Auction\AuctionLotService;
use Illuminate\Support\Facades\Mail;

/*
 * If Sale bidding is DISABLED platform-wide.
 *
 * Once a lot's live auction closes and it enters if_sale status, all bidding
 * actions — including a winner increasing their own bid — must be rejected on
 * every platform. These tests lock in that the service and the HTTP endpoint
 * refuse the action so a direct API call cannot bypass the disabled UI.
 */

// ── Service tests ─────────────────────────────────────────────────────────────

test('if_sale bid increase is rejected for the winner (feature disabled)', function () {
    $winner  = User::factory()->create(['status' => 'active']);
    $lot     = $this->createIfSaleLot($winner, ['current_bid' => 1000]);
    $service = app(AuctionLotService::class);

    expect(fn () => $service->increaseIfSaleBid($lot, $winner, $lot->nextMinimumBid()))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

test('if_sale bid increase does not create a Bid or change the lot', function () {
    $winner  = User::factory()->create(['status' => 'active']);
    $lot     = $this->createIfSaleLot($winner, ['current_bid' => 1000]);
    $service = app(AuctionLotService::class);

    try {
        $service->increaseIfSaleBid($lot, $winner, $lot->nextMinimumBid());
    } catch (Illuminate\Validation\ValidationException $e) {
        // expected — feature disabled
    }

    expect(Bid::where('auction_lot_id', $lot->id)->count())->toBe(0)
        ->and($lot->fresh()->current_bid)->toEqual(1000)
        ->and($lot->fresh()->status)->toBe(LotStatus::IfSale);
});

test('if_sale bid increase does not notify the seller', function () {
    Mail::fake();

    $winner  = User::factory()->create(['status' => 'active']);
    $lot     = $this->createIfSaleLot($winner, ['current_bid' => 1000]);
    $service = app(AuctionLotService::class);

    try {
        $service->increaseIfSaleBid($lot, $winner, $lot->nextMinimumBid());
    } catch (Illuminate\Validation\ValidationException $e) {
        // expected — feature disabled
    }

    Mail::assertNothingQueued();
    Mail::assertNothingSent();
});

// ── HTTP endpoint tests ───────────────────────────────────────────────────────

test('POST if-sale/increase-bid is rejected with 422 (feature disabled)', function () {
    $winner  = User::factory()->create(['status' => 'active']);
    $this->givePaymentMethod($winner);
    $lot     = $this->createIfSaleLot($winner);
    $auction = Auction::find($lot->auction_id);
    $this->acceptCurrentTerms($winner, $auction->id);

    $response = $this->actingAs($winner)->postJson(
        "/api/v1/auctions/{$auction->id}/lots/{$lot->id}/if-sale/increase-bid",
        ['amount' => $lot->nextMinimumBid()]
    );

    $response->assertStatus(422);

    // No bid was recorded and the lot is untouched.
    expect(Bid::where('auction_lot_id', $lot->id)->count())->toBe(0)
        ->and($lot->fresh()->status)->toBe(LotStatus::IfSale);
});

test('unauthenticated request gets 401', function () {
    $winner  = User::factory()->create(['status' => 'active']);
    $lot     = $this->createIfSaleLot($winner);
    $auction = Auction::find($lot->auction_id);

    $this->postJson(
        "/api/v1/auctions/{$auction->id}/lots/{$lot->id}/if-sale/increase-bid",
        ['amount' => $lot->nextMinimumBid()]
    )->assertStatus(401);
});

test('inactive user account gets 403', function () {
    $winner  = User::factory()->create(['status' => 'pending_activation']);
    $lot     = $this->createIfSaleLot($winner);
    $auction = Auction::find($lot->auction_id);

    $this->actingAs($winner)->postJson(
        "/api/v1/auctions/{$auction->id}/lots/{$lot->id}/if-sale/increase-bid",
        ['amount' => $lot->nextMinimumBid()]
    )->assertStatus(403);
});
