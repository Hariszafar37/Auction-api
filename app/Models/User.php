<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\GovProfile;
use App\Models\PowerOfAttorney;
use App\Models\SellerProfile;
use App\Models\UserBusinessInformation;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected string $guard_name = 'sanctum';

    protected $fillable = [
        'name',
        'first_name',
        'middle_name',
        'last_name',
        'email',
        'password',
        'phone',
        'primary_phone',
        'secondary_phone',
        'consent_marketing',
        'agreed_terms_at',
        'password_set_at',
        'status',
        'account_type',
        'account_intent',
        'activation_completed_at',
        'stripe_customer_id',
        'registration_ip_address',
        'terms_version',
        'agree_bidder_terms',
        'agree_ecomm_consent',
        'agree_accuracy_confirmed',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'        => 'datetime',
            'password'                 => 'hashed',
            'agreed_terms_at'          => 'datetime',
            'password_set_at'          => 'datetime',
            'activation_completed_at'  => 'datetime',
            'consent_marketing'        => 'boolean',
            'agree_bidder_terms'       => 'boolean',
            'agree_ecomm_consent'      => 'boolean',
            'agree_accuracy_confirmed' => 'boolean',
        ];
    }

    // ── Relationships ────────────────────────────────────────────

    public function buyerProfile(): HasOne
    {
        return $this->hasOne(BuyerProfile::class);
    }

    public function dealerProfile(): HasOne
    {
        return $this->hasOne(DealerProfile::class);
    }

    public function businessProfile(): HasOne
    {
        return $this->hasOne(BusinessProfile::class);
    }

    public function sellerProfile(): HasOne
    {
        return $this->hasOne(SellerProfile::class);
    }

    public function accountInformation(): HasOne
    {
        return $this->hasOne(UserAccountInformation::class);
    }

    public function dealerInformation(): HasOne
    {
        return $this->hasOne(UserDealerInformation::class);
    }

    public function billingInformation(): HasOne
    {
        return $this->hasOne(UserBillingInformation::class);
    }

    public function businessInformation(): HasOne
    {
        return $this->hasOne(UserBusinessInformation::class);
    }

    public function govProfile(): HasOne
    {
        return $this->hasOne(GovProfile::class);
    }

    public function powerOfAttorneys(): HasMany
    {
        return $this->hasMany(PowerOfAttorney::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(UserDocument::class);
    }

    // ── Auction relationships ─────────────────────────────────────

    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class);
    }

    public function proxyBids(): HasMany
    {
        return $this->hasMany(ProxyBid::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'seller_id');
    }

    // ── Status helpers ────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function isPendingEmailVerification(): bool
    {
        return $this->status === 'pending_email_verification';
    }

    public function isPendingPassword(): bool
    {
        return $this->status === 'pending_password';
    }

    public function isPendingActivation(): bool
    {
        return $this->status === 'pending_activation';
    }

    // ── Activation helpers ────────────────────────────────────────

    /**
     * Returns: 'incomplete' | 'pending_approval' | 'complete'
     */
    public function getActivationStatus(): string
    {
        if ($this->status === 'active') {
            return 'complete';
        }

        // Government accounts wait for admin approval without going through the wizard,
        // so activation_completed_at is never set for them. Detect by status alone.
        if ($this->account_type === 'government' && $this->status === 'pending_activation') {
            return 'pending_approval';
        }

        if ($this->activation_completed_at && in_array($this->account_type, ['dealer', 'business'])) {
            return 'pending_approval';
        }

        return 'incomplete';
    }

    public function isActivationRequired(): bool
    {
        // Government accounts are admin-controlled and never go through the self-activation wizard.
        if ($this->account_type === 'government') {
            return false;
        }

        return $this->status !== 'active';
    }

    // ── Payment helpers ───────────────────────────────────────────────────────

    /**
     * Whether the user has a non-expired payment method on file.
     *
     * Source of truth for the payment portion of the bid gate. Exposed to
     * the frontend via UserResource.has_valid_payment_method so the UI can
     * deep-link users to /payment-information.
     *
     * Returns true iff a billing_information row exists AND its stored card
     * metadata (brand / last_four / expiry) is present and the expiry is
     * current month or later. Raw PAN/CVV is never stored, so this is purely
     * a metadata check.
     */
    public function hasValidPaymentMethod(): bool
    {
        return (bool) $this->billingInformation?->hasValidCard();
    }

    // ── Bid eligibility (unified gate) ────────────────────────────────────────

    /**
     * Single source of truth for whether this user may currently place a bid.
     *
     * Mirrored on the frontend via UserResource.can_bid so the UI can
     * pre-emptively disable the bid button. BiddingService::placeBid and
     * setProxyBid re-check this server-side — the cached flag can be stale
     * (a card can expire between requests, an admin can suspend an account).
     *
     * Current rules:
     *   - account status must be 'active' (blocks pending_* and suspended)
     *   - must have a non-expired payment method on file
     *
     * Future-safe extension points (reserved, not yet wired):
     *   - deposit requirements per lot / auction
     *   - outstanding credit balance
     *   - KYC verification state
     *
     * All new rules should be added to BOTH this method and
     * getBidIneligibilityReason() so the frontend can emit a targeted error.
     */
    public function canBid(): bool
    {
        return $this->getBidIneligibilityReason() === null;
    }

    /**
     * Returns the first reason this user cannot bid, or null if they can.
     *
     * Checked in priority order — most specific first — so the frontend
     * can show the correct remediation UI. "suspended" is more actionable
     * than the generic "inactive_account" even though both fail isActive().
     *
     * Reason enum (keep in sync with BidNotAllowedException docblock):
     *   suspended | inactive_account | missing_payment | null
     */
    public function getBidIneligibilityReason(): ?string
    {
        if ($this->isSuspended()) {
            return 'suspended';
        }

        if (! $this->isActive()) {
            return 'inactive_account';
        }

        if (! $this->hasValidPaymentMethod()) {
            return 'missing_payment';
        }

        // Future rules (deposit / credit / KYC) plug in here, above the
        // implicit null return. Each new rule needs a matching reason string
        // so the frontend switch in BidBlockedModal can route correctly.

        return null;
    }

    // ── Compliance helpers ────────────────────────────────────────────────────

    /**
     * Whether the user has an admin-approved Power of Attorney on file.
     */
    public function hasApprovedPoa(): bool
    {
        return $this->powerOfAttorneys()->where('status', 'approved')->exists();
    }

    /**
     * Whether this account has selling intent and therefore requires an approved POA
     * before submitting vehicles to auction.
     *
     * Covers all three seller-enabled account types per client requirement:
     *   - individual sellers  (role:seller assigned by admin after application)
     *   - dealer sellers      (role:dealer — dealers always have buyer_and_seller intent)
     *   - business sellers    (account_intent includes 'seller' or 'buyer_and_seller')
     */
    public function hasSellIntent(): bool
    {
        // Dealers always sell — intent is hardcoded to buyer_and_seller at activation
        if ($this->hasRole('dealer')) {
            return true;
        }

        // Individual sellers explicitly approved by admin
        if ($this->hasRole('seller')) {
            return true;
        }

        // Business (and any other) accounts with explicit selling intent
        return in_array($this->account_intent, ['seller', 'buyer_and_seller'], true);
    }

    /**
     * Whether the user's dealer classification is wholesale (any variant).
     */
    public function isWholesaleDealer(): bool
    {
        $classification = $this->dealerProfile?->dealer_classification;
        return in_array($classification, ['maryland_wholesale', 'out_of_state_wholesale'], true);
    }

    /**
     * Whether this user may perform seller write actions right now.
     *
     * Requires BOTH:
     *   (a) an active account status (status === 'active'), AND
     *   (b) selling intent (dealer role, seller role, or seller account_intent).
     *
     * Dealer/business accounts at 'pending_activation' (awaiting admin approval)
     * must return false even though they already hold role:dealer — their
     * permissions are unlocked only when an admin promotes status to 'active'.
     *
     * This is the single source of truth mirrored on the frontend
     * (src/modules/auth/helpers.ts::canPerformSellerActions). Admins bypass
     * seller endpoints entirely and are never checked with this helper.
     */
    public function canPerformSellerActions(): bool
    {
        return $this->isActive() && $this->hasSellIntent();
    }
}
