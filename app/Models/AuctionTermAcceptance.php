<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable proof that a user accepted the auction Terms & Conditions for a
 * specific auction at a specific version. See AuctionTerm for the master doc.
 */
class AuctionTermAcceptance extends Model
{
    protected $fillable = [
        'auction_id',
        'auction_terms_id',
        'user_id',
        'terms_version',
        'accepted_at',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
    ];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function auctionTerm(): BelongsTo
    {
        return $this->belongsTo(AuctionTerm::class, 'auction_terms_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
