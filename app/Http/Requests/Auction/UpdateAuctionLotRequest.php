<?php

namespace App\Http\Requests\Auction;

use App\Support\AuctionTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAuctionLotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Convert the lot's wall-clock closing time into a UTC instant, in the
     * parent auction's zone. Sending null clears the override and falls the lot
     * back to the auction-wide time, so a present-but-null key must survive.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('scheduled_close_at')) {
            $this->merge([
                'scheduled_close_at' => AuctionTime::toUtc(
                    $this->input('scheduled_close_at'),
                    $this->route('auction')?->timezone,
                ),
            ]);
        }
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
