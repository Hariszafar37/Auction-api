<?php

namespace App\Services\Auction;

use App\Models\Auction;
use App\Models\AuctionTerm;
use App\Models\AuctionTermAcceptance;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Single source of truth for the Auction Entry gate: who must accept terms,
 * who must hold a payment method, whether a given user may enter a given
 * auction, and recording acceptances.
 *
 * The frontend consumes eligibility() to render the gate; BiddingService and
 * BidController re-check entryBlockReason() server-side so the gate cannot be
 * bypassed by hitting the bid endpoints directly.
 */
class AuctionTermsService
{
    /** The live master Terms document. */
    public function current(): AuctionTerm
    {
        return AuctionTerm::current();
    }

    /**
     * Terms apply to every logged-in participant (buyers, dealers, sellers).
     * Admin and staff manage auctions rather than participate, so they are
     * exempt from the gate entirely.
     */
    public function requiresTerms(User $user): bool
    {
        return ! $user->hasRole('admin') && ! $user->hasRole('staff');
    }

    /**
     * A valid payment method is required to ENTER only for bidder-capable
     * roles (buyers and dealers). Admin, staff, and sellers are exempt — a
     * seller may need to watch their own auction without a card on file.
     * (Placing a bid is still independently payment-gated via User::canBid().)
     */
    public function requiresPayment(User $user): bool
    {
        return ! $user->hasRole('admin')
            && ! $user->hasRole('staff')
            && ! $user->hasRole('seller');
    }

    /** Has the user accepted the CURRENT terms version for this auction? */
    public function hasAcceptedCurrent(User $user, Auction $auction): bool
    {
        return AuctionTermAcceptance::query()
            ->where('user_id', $user->id)
            ->where('auction_id', $auction->id)
            ->where('terms_version', $this->current()->version)
            ->exists();
    }

    /**
     * Record acceptance of the current terms for an auction. Idempotent: a
     * repeated accept for the same (user, auction, version) returns the
     * existing row rather than erroring on the unique constraint.
     */
    public function accept(User $user, Auction $auction, Request $request): AuctionTermAcceptance
    {
        $terms = $this->current();

        return AuctionTermAcceptance::firstOrCreate(
            [
                'user_id'       => $user->id,
                'auction_id'    => $auction->id,
                'terms_version' => $terms->version,
            ],
            [
                'auction_terms_id' => $terms->id,
                'accepted_at'      => now(),
                'ip_address'       => $request->ip(),
                'user_agent'       => substr((string) $request->userAgent(), 0, 512),
            ],
        );
    }

    /**
     * Full eligibility snapshot for entering an auction. $user is null for
     * unauthenticated visitors.
     *
     * reason values (closed set, mirrored on the frontend):
     *   unauthenticated | terms_not_accepted | missing_payment | null
     */
    public function eligibility(?User $user, Auction $auction): array
    {
        $current = $this->current();

        if (! $user) {
            return [
                'authenticated'            => false,
                'requires_terms'           => true,
                'requires_payment'         => true,
                'terms_accepted'           => false,
                'current_version'          => $current->version,
                'accepted_version'         => null,
                'has_valid_payment_method' => false,
                'can_enter'                => false,
                'reason'                   => 'unauthenticated',
            ];
        }

        $requiresTerms   = $this->requiresTerms($user);
        $requiresPayment = $this->requiresPayment($user);

        $acceptance = AuctionTermAcceptance::query()
            ->where('user_id', $user->id)
            ->where('auction_id', $auction->id)
            ->latest('accepted_at')
            ->first();

        $termsAccepted = ! $requiresTerms
            || ($acceptance !== null && $acceptance->terms_version === $current->version);

        $hasPayment = $user->hasValidPaymentMethod();
        $paymentOk  = ! $requiresPayment || $hasPayment;

        $reason = null;
        if (! $termsAccepted) {
            $reason = 'terms_not_accepted';
        } elseif (! $paymentOk) {
            $reason = 'missing_payment';
        }

        return [
            'authenticated'            => true,
            'requires_terms'           => $requiresTerms,
            'requires_payment'         => $requiresPayment,
            'terms_accepted'           => $termsAccepted,
            'current_version'          => $current->version,
            'accepted_version'         => $acceptance?->terms_version,
            'has_valid_payment_method' => $hasPayment,
            'can_enter'                => $termsAccepted && $paymentOk,
            'reason'                   => $reason,
        ];
    }

    /**
     * Server-side enforcement used at bid time. Returns the blocking reason
     * (terms_not_accepted | missing_payment) or null if the user may proceed.
     * Used by BiddingService / BidController to make the gate bypass-proof.
     */
    public function entryBlockReason(User $user, Auction $auction): ?string
    {
        return $this->eligibility($user, $auction)['reason'];
    }
}
