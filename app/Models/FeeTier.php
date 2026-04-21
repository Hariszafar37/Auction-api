<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeTier extends Model
{
    protected $fillable = [
        'fee_configuration_id',
        'sale_price_from',
        'sale_price_to',
        'fee_calculation_type',
        'fee_amount',
        'sort_order',
    ];

    protected $casts = [
        'sale_price_from'     => 'integer',
        'sale_price_to'       => 'integer',
        'fee_amount'          => 'decimal:2',
        'sort_order'          => 'integer',
    ];

    public function configuration(): BelongsTo
    {
        return $this->belongsTo(FeeConfiguration::class, 'fee_configuration_id');
    }

    public function matchesSalePrice(int $salePrice): bool
    {
        if ($salePrice < $this->sale_price_from) {
            return false;
        }
        if ($this->sale_price_to !== null && $salePrice > $this->sale_price_to) {
            return false;
        }
        return true;
    }
}
