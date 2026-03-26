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
            'location'    => ['nullable', 'string', 'max:255'],
            'timezone'    => ['nullable', 'timezone'],
            'starts_at'   => ['required', 'date', 'after:now'],
            'notes'       => ['nullable', 'string'],
        ];
    }
}
