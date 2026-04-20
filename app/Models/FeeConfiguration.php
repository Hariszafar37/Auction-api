<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeeConfiguration extends Model
{
    protected $fillable = [
        'fee_type',
        'label',
        'calculation_type',
        'amount',
        'min_amount',
        'max_amount',
        'location',
        'applies_to',
        'is_active',
        'sort_order',
        'notes',
    ];

    protected $casts = [
        'amount'     => 'decimal:2',
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function tiers(): HasMany
    {
        return $this->hasMany(FeeTier::class)->orderBy('sort_order')->orderBy('sale_price_from');
    }

    // ─── Scopes ──────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForLocation($query, ?string $location)
    {
        return $query->where('location', $location);
    }

    public function scopeGlobal($query)
    {
        return $query->whereNull('location');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────────

    public function isTiered(): bool
    {
        return $this->calculation_type === 'tiered';
    }
}
