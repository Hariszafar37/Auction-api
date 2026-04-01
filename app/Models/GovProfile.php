<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GovProfile extends Model
{
    protected $table = 'gov_profiles';

    protected $fillable = [
        'user_id',
        'entity_name',
        'entity_subtype',
        'department_division',
        'point_of_contact_name',
        'contact_title',
        'phone',
        'office_phone',
        'address',
        'city',
        'state',
        'zip',
        'approval_status',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
        'admin_notes',
        'invite_token',
        'invite_sent_at',
        'invite_accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at'        => 'datetime',
            'invite_sent_at'     => 'datetime',
            'invite_accepted_at' => 'datetime',
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
