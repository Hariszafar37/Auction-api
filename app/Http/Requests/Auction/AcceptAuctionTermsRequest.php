<?php

namespace App\Http\Requests\Auction;

use Illuminate\Foundation\Http\FormRequest;

class AcceptAuctionTermsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // The single required checkbox must be ticked.
            'agree' => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'agree.accepted' => 'You must agree to the Auction Terms & Conditions to continue.',
            'agree.required' => 'You must agree to the Auction Terms & Conditions to continue.',
        ];
    }
}
