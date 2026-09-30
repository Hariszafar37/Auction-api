<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agreed seller fees for one government consignor. Replaces the global seller
 * fees in PaymentSetting for that account only — see
 * SellerSettlementService::feeProfileFor().
 */
class SellerFeeProfile extends Model
{
    public const COMMISSION_NONE    = 'none';
    public const COMMISSION_PERCENT = 'percent';
    public const COMMISSION_FLAT    = 'flat';

    public const COMMISSION_TYPES = [
        self::COMMISSION_NONE,
        self::COMMISSION_PERCENT,
        self::COMMISSION_FLAT,
    ];

    protected $fillable = [
        'user_id',
        'registration_fee',
        'commission_type',
        'commission_value',
        'no_sale_fee',
        'notes',
        'updated_by',
    ];

    protected $attributes = [
        'registration_fee' => 0,
        'commission_type'  => self::COMMISSION_NONE,
        'commission_value' => 0,
        'no_sale_fee'      => 0,
    ];

    protected function casts(): array
    {
        return [
            'registration_fee' => 'decimal:2',
            'commission_value' => 'decimal:2',
            'no_sale_fee'      => 'decimal:2',
        ];
    }

    /**
     * An unsaved all-zero profile: what a government account with no agreed
     * fees settles on.
     */
    public static function noFees(): self
    {
        return new self();
    }

    public function commissionFor(int $salePrice): float
    {
        return match ($this->commission_type) {
            self::COMMISSION_PERCENT => round($salePrice * ((float) $this->commission_value / 100), 2),
            self::COMMISSION_FLAT    => round((float) $this->commission_value, 2),
            default                  => 0.0,
        };
    }

    /** Audit copy of the terms applied, stored on the settlement. */
    public function snapshot(): array
    {
        return [
            'source'           => 'account_fee_profile',
            'profile_id'       => $this->id,
            'registration_fee' => (float) $this->registration_fee,
            'commission_type'  => $this->commission_type,
            'commission_value' => (float) $this->commission_value,
            'no_sale_fee'      => (float) $this->no_sale_fee,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
