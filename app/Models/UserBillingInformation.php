<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserBillingInformation extends Model
{
    protected $table = 'user_billing_information';

    protected $fillable = [
        'user_id',
        'billing_address',
        'billing_country',
        'billing_city',
        'billing_state',
        'billing_zip_postal_code',
        'payment_method_added',
        'cardholder_name',
        'card_brand',
        'card_last_four',
        'card_expiry_month',
        'card_expiry_year',
    ];

    protected function casts(): array
    {
        return [
            'payment_method_added' => 'boolean',
            'card_expiry_month'    => 'integer',
            'card_expiry_year'     => 'integer',
        ];
    }

    /**
     * True if a card on file exists and its expiry month/year is in the future
     * (inclusive of the current month — cards remain valid through end of month).
     */
    public function hasValidCard(): bool
    {
        if (! $this->payment_method_added) {
            return false;
        }

        if (! $this->card_expiry_month || ! $this->card_expiry_year) {
            return false;
        }

        $now = now();

        if ((int) $this->card_expiry_year > $now->year) {
            return true;
        }

        if ((int) $this->card_expiry_year < $now->year) {
            return false;
        }

        return (int) $this->card_expiry_month >= $now->month;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
