<?php

namespace App\Http\Requests\Auction;

use Illuminate\Foundation\Http\FormRequest;

class CreateAuctionLotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vehicle_id'               => ['required', 'integer', 'exists:vehicles,id'],
            'lot_number'               => ['nullable', 'integer', 'min:1'],
            'starting_bid'             => ['required', 'integer', 'min:100'],
            'reserve_price'            => ['nullable', 'integer', 'min:0'],
            'countdown_seconds'        => ['nullable', 'integer', 'min:10', 'max:300'],
            'requires_seller_approval' => ['nullable', 'boolean'],
        ];
    }
}
