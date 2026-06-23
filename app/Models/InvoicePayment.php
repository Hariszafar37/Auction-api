<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class InvoicePayment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'invoice_id',
        'user_id',
        'method',
        'transaction_type',
        'amount',
        'reference',
        'stripe_client_secret',
        'status',
        'notes',
        'processed_at',
        'received_at',
        'processed_by',
        'created_by',
        'approved_by',
        'approved_at',
        'approval_note',
    ];

    protected $casts = [
        'amount'           => 'decimal:2',
        'method'           => PaymentMethod::class,
        'transaction_type' => PaymentTransactionType::class,
        'processed_at'     => 'datetime',
        'received_at'      => 'datetime',
        'approved_at'      => 'datetime',
    ];

    protected $attributes = [
        'transaction_type' => 'payment',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
