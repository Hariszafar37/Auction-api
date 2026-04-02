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

        if ($this->activation_completed_at && in_array($this->account_type, ['dealer', 'business'])) {
            return 'pending_approval';
        }

        return 'incomplete';
    }

    public function isActivationRequired(): bool
    {
        return $this->status !== 'active';
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
}
