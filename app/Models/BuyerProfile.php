<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuyerProfile extends Model
{
    protected $fillable = [
        'user_id',
        'license_number',
        'license_state',
        'license_photo_path',
        'stripe_payment_method_id',
        'deposit_authorized',
    ];

    protected function casts(): array
    {
        return [
            'deposit_authorized' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
