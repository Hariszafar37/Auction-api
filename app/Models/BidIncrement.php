<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BidIncrement extends Model
{
    protected $fillable = [
        'min_amount',
        'max_amount',
        'increment',
    ];

    protected $casts = [
        'min_amount' => 'integer',
        'max_amount' => 'integer',
        'increment'  => 'integer',
    ];

    /**
     * Resolve the bid increment for a given current bid amount.
     */
    public static function forAmount(int $amount): int
    {
        $row = static::query()
            ->where('min_amount', '<=', $amount)
            ->where(function ($q) use ($amount) {
                $q->whereNull('max_amount')
                  ->orWhere('max_amount', '>=', $amount);
            })
            ->orderByDesc('min_amount')
            ->first();

        return $row?->increment ?? 25; // fallback to $25
    }
}
