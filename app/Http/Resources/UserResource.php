<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
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
            'agreed_terms_at'         => $this->agreed_terms_at?->toIso8601String(),
            'status'                  => $this->status,
            'email_verified_at'       => $this->email_verified_at?->toIso8601String(),
            'roles'                   => $this->getRoleNames(),

            // Activation
            'account_type'            => $this->account_type,
            'account_intent'          => $this->account_intent,
            'activation_status'       => $this->getActivationStatus(),
            'activation_required'     => $this->isActivationRequired(),
            'activation_completed_at' => $this->activation_completed_at?->toIso8601String(),

            // Step data (loaded on demand)
            'account_information'     => $this->whenLoaded('accountInformation', fn () => $this->accountInformation ? [
                'date_of_birth'                  => $this->accountInformation->date_of_birth?->toDateString(),
                'address'                        => $this->accountInformation->address,
                'country'                        => $this->accountInformation->country,
                'state'                          => $this->accountInformation->state,
                'county'                         => $this->accountInformation->county,
                'city'                           => $this->accountInformation->city,
                'zip_postal_code'                => $this->accountInformation->zip_postal_code,
                'driver_license_number'          => $this->accountInformation->driver_license_number,
                'driver_license_expiration_date' => $this->accountInformation->driver_license_expiration_date?->toDateString(),
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
                'billing_zip_postal_code' => $this->billingInformation->billing_zip_postal_code,
                'payment_method_added'    => $this->billingInformation->payment_method_added,
            ] : null),

            'documents'               => $this->whenLoaded('documents', fn () => $this->documents->map(fn ($doc) => [
                'id'            => $doc->id,
                'type'          => $doc->type,
                'original_name' => $doc->original_name,
                'mime_type'     => $doc->mime_type,
                'size_bytes'    => $doc->size_bytes,
                'uploaded_at'   => $doc->created_at->toIso8601String(),
            ])),

            // Business approval tracking (loaded on demand)
            'business_profile'        => $this->whenLoaded('businessProfile', fn () => $this->businessProfile ? [
                'legal_business_name' => $this->businessProfile->legal_business_name,
                'entity_type'         => $this->businessProfile->entity_type,
                'approval_status'     => $this->businessProfile->approval_status,
                'rejection_reason'    => $this->businessProfile->rejection_reason,
                'reviewed_at'         => $this->businessProfile->reviewed_at?->toIso8601String(),
            ] : null),

            // Legacy profile data (kept for admin compatibility)
            'dealer_profile'          => $this->whenLoaded('dealerProfile', fn () => $this->dealerProfile ? [
                'company_name'    => $this->dealerProfile->company_name,
                'dealer_license'  => $this->dealerProfile->dealer_license,
                'approval_status' => $this->dealerProfile->approval_status,
                'rejection_reason'=> $this->dealerProfile->rejection_reason,
                'reviewed_at'     => $this->dealerProfile->reviewed_at?->toIso8601String(),
            ] : null),

            'created_at'              => $this->created_at->toIso8601String(),
        ];
    }
}
