<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDocument extends Model
{
    protected $table = 'user_documents';

    protected $fillable = [
        'user_id',
        'type',
        'file_path',
        'disk',
        'original_name',
        'mime_type',
        'size_bytes',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
