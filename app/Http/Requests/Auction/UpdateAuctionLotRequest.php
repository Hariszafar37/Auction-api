<?php

namespace App\Http\Requests\Auction;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAuctionLotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lot_number'               => ['sometimes', 'integer', 'min:1'],
            'starting_bid'             => ['sometimes', 'integer', 'min:100'],
            'reserve_price'            => ['nullable', 'integer', 'min:0'],
            'countdown_seconds'        => ['nullable', 'integer', 'min:10', 'max:300'],
            'requires_seller_approval' => ['nullable', 'boolean'],
        ];
    }
}
