<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserBusinessInformation extends Model
{
    protected $table = 'user_business_information';

    protected $fillable = [
        'user_id',
        'legal_business_name',
        'dba_name',
        'primary_contact_name',
        'contact_title',
        'phone',
        'office_phone',
        'address',
        'suite',
        'city',
        'state',
        'zip',
        'entity_type',
        'state_of_formation',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
