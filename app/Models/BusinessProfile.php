<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessProfile extends Model
{
    protected $fillable = [
        'user_id',
        'legal_business_name',
        'entity_type',
        'approval_status',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
        'packet_accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'packet_accepted_at' => 'datetime',
            'reviewed_at'        => 'datetime',
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
