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
            'location'    => ['nullable', 'string', 'max:255'],
            'timezone'    => ['nullable', 'timezone'],
            'starts_at'   => ['sometimes', 'date', 'after:now'],
            'notes'       => ['nullable', 'string'],
        ];
    }
}
