<?php

namespace App\Http\Resources;

use App\Support\SignedFileUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Safely convert a date value to an ISO-8601 string.
     *
     * Handles three cases without throwing:
     *   - Carbon instance  → direct call
     *   - string / numeric → Carbon::parse() then format
     *   - null             → null
     *
     * This guards against legacy rows where a datetime column was stored
     * as a plain string before proper model casting was in place.
     */
    private function safeIso(mixed $date): ?string
    {
        if ($date === null) {
            return null;
        }

        if ($date instanceof \Carbon\CarbonInterface) {
            return $date->toIso8601String();
        }

        return \Carbon\Carbon::parse($date)->toIso8601String();
    }

    public function toArray(Request $request): array
    {
        return [
            'id'                      => $this->id,
            'name'                    => $this->name,
            'first_name'              => $this->first_name,
            'last_name'               => $this->last_name,
            'email'                   => $this->email,
            'primary_phone'           => $this->primary_phone,
            'secondary_phone'         => $this->secondary_phone,
            'consent_marketing'       => $this->consent_marketing,
            'agreed_terms_at'         => $this->safeIso($this->agreed_terms_at),
            'status'                  => $this->status,
            'email_verified_at'       => $this->safeIso($this->email_verified_at),
            'roles'                   => $this->getRoleNames(),

            // Activation
            'account_type'            => $this->account_type,
            'account_intent'          => $this->account_intent,
            'activation_status'       => $this->getActivationStatus(),
            'activation_required'     => $this->isActivationRequired(),
            'activation_completed_at' => $this->safeIso($this->activation_completed_at),

            // Payment-method sub-flag — the frontend uses this to know
            // whether to deep-link into /payment-information. Backed by
            // User::hasValidPaymentMethod().
            'has_valid_payment_method'  => $this->hasValidPaymentMethod(),

            // Unified bid-eligibility gate — single source of truth the
            // frontend checks before enabling the "Place Bid" button. See
            // User::canBid() for the full rule set. BiddingService re-checks
            // this server-side on every bid and throws BidNotAllowedException
            // (code: BID_NOT_ALLOWED) with a reason enum the UI switches on.
            'can_bid'                   => $this->canBid(),
            'bid_ineligibility_reason'  => $this->getBidIneligibilityReason(),

            // Step data (loaded on demand)
            'account_information'     => $this->whenLoaded('accountInformation', fn () => $this->accountInformation ? [
                'date_of_birth'      => $this->accountInformation->date_of_birth?->toDateString(),
                'address'            => $this->accountInformation->address,
                'country'            => $this->accountInformation->country,
                'state'              => $this->accountInformation->state,
                'county'             => $this->accountInformation->county,
                'city'               => $this->accountInformation->city,
                'zip_postal_code'    => $this->accountInformation->zip_postal_code,
                'id_type'            => $this->accountInformation->id_type,
                'id_number'          => $this->accountInformation->id_number,
                'id_issuing_state'   => $this->accountInformation->id_issuing_state,
                'id_issuing_country' => $this->accountInformation->id_issuing_country,
                'id_expiry'          => $this->accountInformation->id_expiry?->toDateString(),
            ] : null),

            'dealer_information'      => $this->whenLoaded('dealerInformation', fn () => $this->dealerInformation ? [
                'company_name'            => $this->dealerInformation->company_name,
                'owner_name'              => $this->dealerInformation->owner_name,
                'phone'                   => $this->dealerInformation->phone,
                'primary_contact'         => $this->dealerInformation->primary_contact,
                'license_number'          => $this->dealerInformation->license_number,
                'license_expiration_date' => $this->dealerInformation->license_expiration_date?->toDateString(),
                'tax_id_number'           => $this->dealerInformation->tax_id_number,
                'dealer_address'          => $this->dealerInformation->dealer_address,
                'dealer_country'          => $this->dealerInformation->dealer_country,
                'dealer_city'             => $this->dealerInformation->dealer_city,
                'dealer_state'            => $this->dealerInformation->dealer_state,
                'dealer_zip_code'         => $this->dealerInformation->dealer_zip_code,
            ] : null),

            'business_information'    => $this->whenLoaded('businessInformation', fn () => $this->businessInformation ? [
                'legal_business_name'  => $this->businessInformation->legal_business_name,
                'dba_name'             => $this->businessInformation->dba_name,
                'primary_contact_name' => $this->businessInformation->primary_contact_name,
                'contact_title'        => $this->businessInformation->contact_title,
                'phone'                => $this->businessInformation->phone,
                'office_phone'         => $this->businessInformation->office_phone,
                'address'              => $this->businessInformation->address,
                'suite'                => $this->businessInformation->suite,
                'city'                 => $this->businessInformation->city,
                'state'                => $this->businessInformation->state,
                'zip'                  => $this->businessInformation->zip,
                'entity_type'          => $this->businessInformation->entity_type,
                'state_of_formation'   => $this->businessInformation->state_of_formation,
            ] : null),

            'billing_information'     => $this->whenLoaded('billingInformation', fn () => $this->billingInformation ? [
                'billing_address'         => $this->billingInformation->billing_address,
                'billing_country'         => $this->billingInformation->billing_country,
                'billing_city'            => $this->billingInformation->billing_city,
                'billing_state'           => $this->billingInformation->billing_state,
                'billing_zip_postal_code' => $this->billingInformation->billing_zip_postal_code,
                'payment_method_added'    => $this->billingInformation->payment_method_added,
                // Card metadata (PCI-safe — no PAN / CVV is ever stored)
                'cardholder_name'         => $this->billingInformation->cardholder_name,
                'card_brand'              => $this->billingInformation->card_brand,
                'card_last_four'          => $this->billingInformation->card_last_four,
                'card_expiry_month'       => $this->billingInformation->card_expiry_month,
                'card_expiry_year'        => $this->billingInformation->card_expiry_year,
            ] : null),

            'documents'               => $this->whenLoaded('documents', fn () => $this->documents->map(fn ($doc) => [
                'id'            => $doc->id,
                'type'          => $doc->type,
                'original_name' => $doc->original_name,
                'mime_type'     => $doc->mime_type,
                'size_bytes'    => $doc->size_bytes,
                'status'        => $doc->status ?? 'pending_review',
                'admin_notes'   => $doc->admin_notes,
                'reviewed_by'   => $doc->reviewed_by,
                'reviewed_at'   => $this->safeIso($doc->reviewed_at),
                // Signed streaming URL — see App\Support\SignedFileUrl. Passing
                // the current authenticated user triggers a mint-time policy
                // check (UserDocumentPolicy) AND embeds viewer_id into the
                // signed URL for download-time re-verification. Returns null
                // for viewers who cannot see the file.
                'url'           => SignedFileUrl::userDocument($doc, $request->user()),
                'uploaded_at'   => $this->safeIso($doc->created_at),
            ])),

            // Business approval tracking (loaded on demand)
            'business_profile'        => $this->whenLoaded('businessProfile', fn () => $this->businessProfile ? [
                'legal_business_name' => $this->businessProfile->legal_business_name,
                'entity_type'         => $this->businessProfile->entity_type,
                'approval_status'     => $this->businessProfile->approval_status,
                'rejection_reason'    => $this->businessProfile->rejection_reason,
                'reviewed_at'         => $this->safeIso($this->businessProfile->reviewed_at),
            ] : null),

            // Individual seller approval tracking (loaded on demand)
            'seller_profile'          => $this->whenLoaded('sellerProfile', fn () => $this->sellerProfile ? [
                'approval_status'   => $this->sellerProfile->approval_status,
                'rejection_reason'  => $this->sellerProfile->rejection_reason,
                'reviewed_at'       => $this->safeIso($this->sellerProfile->reviewed_at),
                'applied_at'        => $this->safeIso($this->sellerProfile->created_at),
            ] : null),

            // Legacy profile data (kept for admin compatibility)
            'dealer_profile'          => $this->whenLoaded('dealerProfile', fn () => $this->dealerProfile ? [
                'company_name'    => $this->dealerProfile->company_name,
                'dealer_license'  => $this->dealerProfile->dealer_license,
                'approval_status' => $this->dealerProfile->approval_status,
                'rejection_reason'=> $this->dealerProfile->rejection_reason,
                'reviewed_at'     => $this->safeIso($this->dealerProfile->reviewed_at),
            ] : null),

            'created_at'              => $this->safeIso($this->created_at),
        ];
    }
}
