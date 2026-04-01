<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDealerInformation extends Model
{
    protected $table = 'user_dealer_information';

    protected $fillable = [
        'user_id',
        'company_name',
        'dba_name',
        'owner_name',
        'phone',
        'office_phone',
        'primary_contact',
        'license_number',
        'license_expiration_date',
        'tax_id_number',
        'dealer_address',
        'dealer_country',
        'dealer_city',
        'dealer_state',
        'dealer_zip_code',
        'dealer_type',
        'salesman_name',
        'salesman_license_number',
        'salesman_license_state',
        'salesman_license_expiry',
    ];

    protected function casts(): array
    {
        return [
            'license_expiration_date' => 'date',
            'salesman_license_expiry' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
