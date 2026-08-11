<?php

namespace App\Http\Requests\Auction;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAuctionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'       => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'location'    => ['sometimes', 'nullable', 'string', 'max:255'],
            'location_id' => ['sometimes', 'nullable', 'integer', 'exists:locations,id'],
            'timezone'    => ['nullable', 'timezone'],
            'starts_at'   => ['sometimes', 'date', 'after:now'],
            // Editable while the auction is live too, so the closing time can be
            // pushed back mid-sale. AuctionService enforces "after the start".
            'scheduled_end_at' => ['sometimes', 'nullable', 'date'],
            'notes'       => ['nullable', 'string'],
        ];
    }
}
