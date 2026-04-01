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
        // Legacy columns — kept for backward compatibility with existing test fixtures
        'driver_license_number',
        'driver_license_expiration_date',
        // New generic ID columns (Phase 2 compliance)
        'id_type',
        'id_number',
        'id_issuing_state',
        'id_issuing_country',
        'id_expiry',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth'                  => 'date',
            'driver_license_expiration_date' => 'date',
            'id_expiry'                      => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
