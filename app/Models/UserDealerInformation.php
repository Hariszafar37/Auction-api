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
        'owner_name',
        'phone',
        'primary_contact',
        'license_number',
        'license_expiration_date',
        'tax_id_number',
        'dealer_address',
        'dealer_country',
        'dealer_city',
        'dealer_zip_code',
    ];

    protected function casts(): array
    {
        return [
            'license_expiration_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
