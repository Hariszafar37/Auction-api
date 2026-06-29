<?php

namespace App\Http\Requests\Auction;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Admin update of the master Auction Terms. The version is auto-incremented on
 * save (handled in AuctionTerm::publishUpdate) — admins never set it directly.
 */
class UpdateAuctionTermsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is already behind role:admin
    }

    public function rules(): array
    {
        return [
            'header'                  => ['sometimes', 'string', 'max:255'],
            'intro'                   => ['sometimes', 'nullable', 'string', 'max:2000'],
            'important_information'   => ['sometimes', 'array'],
            'important_information.*' => ['string', 'max:1000'],
            'full_terms_content'      => ['sometimes', 'nullable', 'string'],
            'checkbox_label'          => ['sometimes', 'string', 'max:500'],
            'fees_url'                => ['sometimes', 'nullable', 'url', 'max:2048'],
            'payment_policy_url'      => ['sometimes', 'nullable', 'url', 'max:2048'],
        ];
    }
}
