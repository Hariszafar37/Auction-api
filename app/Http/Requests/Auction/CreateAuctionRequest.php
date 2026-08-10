<?php

namespace App\Http\Requests\Auction;

use Illuminate\Foundation\Http\FormRequest;

class CreateAuctionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'location'    => ['sometimes', 'nullable', 'string', 'max:255'],
            'location_id' => ['sometimes', 'nullable', 'integer', 'exists:locations,id'],
            'timezone'    => ['nullable', 'timezone'],
            'starts_at'   => ['required', 'date', 'after:now'],
            // Auction-wide closing time. Lots without their own scheduled_close_at
            // start their final countdown at this moment. Optional — omit it to
            // keep the sale fully manual.
            'scheduled_end_at' => ['nullable', 'date', 'after:starts_at'],
            'notes'       => ['nullable', 'string'],
        ];
    }
}
