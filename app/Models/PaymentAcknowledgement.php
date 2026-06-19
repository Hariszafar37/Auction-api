<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAcknowledgement extends Model
{
    protected $fillable = [
        'invoice_id',
        'user_id',
        'payment_method',
        'acknowledged_at',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'payment_method'  => PaymentMethod::class,
        'acknowledged_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
