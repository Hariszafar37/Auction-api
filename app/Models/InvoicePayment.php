<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoicePayment extends Model
{
    protected $fillable = [
        'invoice_id',
        'user_id',
        'method',
        'amount',
        'reference',
        'stripe_client_secret',
        'status',
        'notes',
        'processed_at',
        'processed_by',
        'approved_by',
        'approved_at',
        'approval_note',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'method'       => PaymentMethod::class,
        'processed_at' => 'datetime',
        'approved_at'  => 'datetime',
    ];

    protected $hidden = ['stripe_client_secret'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
