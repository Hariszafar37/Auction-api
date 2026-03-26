<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAccountInformation extends Model
{
    protected $table = 'user_account_information';

    protected $fillable = [
        'user_id',
        'date_of_birth',
        'address',
        'country',
        'state',
        'county',
        'city',
        'zip_postal_code',
        'driver_license_number',
        'driver_license_expiration_date',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth'                  => 'date',
            'driver_license_expiration_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
