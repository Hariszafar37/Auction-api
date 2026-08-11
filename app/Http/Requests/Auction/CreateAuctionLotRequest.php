<?php

namespace App\Http\Requests\Auction;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'lot_number'               => [
                'nullable', 'integer', 'min:1',
                // When provided, a manual lot number must be unique within the auction.
                Rule::unique('auction_lots', 'lot_number')
                    ->where('auction_id', $this->route('auction')?->id),
            ],
            'starting_bid'             => ['required', 'integer', 'min:100'],
            'reserve_price'            => ['nullable', 'integer', 'min:0'],
            'countdown_seconds'        => ['nullable', 'integer', 'min:10', 'max:300'],
            // Per-lot closing time — overrides the auction-wide scheduled_end_at.
            // Used to run lots one after another like a live ring. Must land
            // after the auction starts: a time before it would put the lot into
            // countdown the moment the auction goes live and no-sale a vehicle
            // that never got a chance to take a bid.
            'scheduled_close_at'       => ['nullable', 'date', 'after:' . $this->auctionStartsAt()],
            'requires_seller_approval' => ['nullable', 'boolean'],
        ];
    }

    /** Start time of the auction this lot is being added to, for the after: rule. */
    private function auctionStartsAt(): string
    {
        $startsAt = $this->route('auction')?->starts_at;

        return $startsAt ? $startsAt->toDateTimeString() : 'now';
    }

    public function messages(): array
    {
        return [
            'scheduled_close_at.after' => 'The lot closing time must be after the auction starts.',
        ];
    }
}
