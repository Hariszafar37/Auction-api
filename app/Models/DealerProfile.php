<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealerProfile extends Model
{
    protected $fillable = [
        'user_id',
        'company_name',
        'owner_name',
        'phone_primary',
        'primary_contact',
        'dealer_license',
        'dealer_license_expiration_date',
        'tax_id_number',
        'packet_accepted_at',
        'approval_status',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
        'dealer_address_line1',
        'dealer_address_line2',
        'dealer_city',
        'dealer_state',
        'dealer_postal_code',
        'dealer_country',
        'dealer_license_document_path',
    ];

    protected function casts(): array
    {
        return [
            'packet_accepted_at'             => 'datetime',
            'reviewed_at'                    => 'datetime',
            'dealer_license_expiration_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
