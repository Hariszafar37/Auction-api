<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

// Shared auction factory helpers — scoped to auction tests only.
pest()->use(Tests\Helpers\CreatesAuctionData::class)
    ->in('Feature/Auction');

// Shared auction factory helpers — also available in compliance tests.
pest()->use(Tests\Helpers\CreatesAuctionData::class)
    ->in('Feature/Compliance');

// Shared invoice/payment factory helpers — scoped to payment tests.
pest()->use(Tests\Helpers\CreatesInvoiceData::class)
    ->in('Feature/Payment');

// Shared purchase/pickup factory helpers — scoped to purchase tests.
pest()->use(Tests\Helpers\CreatesPurchaseData::class)
    ->in('Feature/Purchase');

/*
|--------------------------------------------------------------------------
| Global helpers
|--------------------------------------------------------------------------
*/

/**
 * Record acceptance of the current auction Terms for a user so they satisfy
 * the entry gate enforced at bid time (BiddingService::requireTermsAccepted /
 * BidController::increaseIfSaleBid). Any test placing a bid for a user expected
 * to succeed (or to reach a later validation step) must call this first.
 */
function acceptAuctionTerms(\App\Models\User $user, int $auctionId): void
{
    $terms = \App\Models\AuctionTerm::current();

    \App\Models\AuctionTermAcceptance::firstOrCreate(
        [
            'user_id'       => $user->id,
            'auction_id'    => $auctionId,
            'terms_version' => $terms->version,
        ],
        [
            'auction_terms_id' => $terms->id,
            'accepted_at'      => now(),
            'ip_address'       => '127.0.0.1',
        ],
    );
}
