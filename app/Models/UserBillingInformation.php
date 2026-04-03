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
    ];

    protected function casts(): array
    {
        return [
            'payment_method_added' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
