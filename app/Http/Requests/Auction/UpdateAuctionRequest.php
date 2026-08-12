<?php

namespace App\Http\Requests\Auction;

use App\Support\AuctionTime;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAuctionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Convert the admin's wall-clock input into UTC instants before validation.
     *
     * The zone comes from this request when the admin changed the dropdown,
     * otherwise from the auction as stored. Changing the dropdown and the time
     * together is one statement — "10:00 PM, in this zone" — so the new zone
     * reinterprets whatever digits were submitted alongside it.
     */
    protected function prepareForValidation(): void
    {
        $tz = $this->input('timezone') ?? $this->route('auction')?->timezone;

        foreach (['starts_at', 'scheduled_end_at'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => AuctionTime::toUtc($this->input($field), $tz)]);
            }
        }
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
