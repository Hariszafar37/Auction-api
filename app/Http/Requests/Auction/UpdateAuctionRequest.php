<?php

namespace App\Http\Requests\Auction;

use App\Support\AuctionTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Throwable;

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
            // `after:now` guards a start time the admin actually *changed*.
            // Re-submitting the stored value stays legal even once it has
            // slipped into the past, because the edit form round-trips every
            // field on save: a draft whose start time went by would otherwise
            // reject an edit to its *title*, leaving the admin unable to fix
            // the very time that caused the rejection.
            'starts_at'   => array_merge(
                ['sometimes', 'date'],
                $this->startsAtChanged() ? ['after:now'] : [],
            ),
            // Editable while the auction is live too, so the closing time can be
            // pushed back mid-sale. AuctionService enforces "after the start".
            'scheduled_end_at' => ['sometimes', 'nullable', 'date'],
            'notes'       => ['nullable', 'string'],
        ];
    }

    /**
     * Whether the submitted start time differs from the one already stored.
     *
     * Runs after prepareForValidation(), so both sides are UTC instants.
     *
     * Compared at minute precision because that is all a `datetime-local` input
     * can express — a stored value carrying seconds would otherwise read as
     * "changed" on every round trip and re-arm the `after:now` rule this method
     * exists to relax.
     */
    private function startsAtChanged(): bool
    {
        $stored    = $this->route('auction')?->starts_at;
        $submitted = $this->input('starts_at');

        // Nothing to compare against — fall back to the strict rule.
        if ($stored === null || ! is_string($submitted) || trim($submitted) === '') {
            return true;
        }

        try {
            return ! CarbonImmutable::parse($submitted)->startOfMinute()->equalTo(
                CarbonImmutable::instance($stored)->startOfMinute(),
            );
        } catch (Throwable) {
            // Unparseable: say "changed" and let the `date` rule reject it with
            // a proper field error rather than swallowing it here.
            return true;
        }
    }
}
