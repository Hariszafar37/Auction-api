<?php

namespace App\Http\Requests\Auction;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAuctionLotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lot_number'               => [
                'sometimes', 'integer', 'min:1',
                // Lot numbers must stay unique within their auction; ignore this lot itself.
                Rule::unique('auction_lots', 'lot_number')
                    ->where('auction_id', $this->route('auction')?->id)
                    ->ignore($this->route('lot')?->id),
            ],
            'starting_bid'             => ['sometimes', 'integer', 'min:100'],
            'reserve_price'            => ['nullable', 'integer', 'min:0'],
            'countdown_seconds'        => ['nullable', 'integer', 'min:10', 'max:300'],
            // Stays editable while the lot is open so the ring order can be
            // adjusted mid-sale. Send null to fall back to the auction-wide time.
            'scheduled_close_at'       => ['sometimes', 'nullable', 'date'],
            'requires_seller_approval' => ['nullable', 'boolean'],
        ];
    }
}
