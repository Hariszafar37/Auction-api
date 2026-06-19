<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton configuration for the payment workflow. Use PaymentSetting::current()
 * to read; there is only ever one row (id = 1), seeded with sane defaults.
 */
class PaymentSetting extends Model
{
    protected $fillable = [
        'business_days_to_pay',
        'late_fee_amount',
        'method_stripe_enabled',
        'method_wire_enabled',
        'method_cash_enabled',
        'method_check_enabled',
        'payment_location',
        'office_hours',
        'check_payable_to',
        'check_mailing_address',
        'accepted_carriers',
        'wire_beneficiary_name',
        'wire_bank_name',
        'wire_routing_number',
        'wire_account_number',
        'wire_swift_code',
        'wire_business_address',
        'wire_notes',
        'payment_deadline_notice',
        'acknowledgment_text',
    ];

    protected $casts = [
        'business_days_to_pay'  => 'integer',
        'late_fee_amount'       => 'decimal:2',
        'method_stripe_enabled' => 'boolean',
        'method_wire_enabled'   => 'boolean',
        'method_cash_enabled'   => 'boolean',
        'method_check_enabled'  => 'boolean',
        'accepted_carriers'     => 'array',
    ];

    /**
     * The one-and-only settings row. Created with defaults on first access so
     * the app never hits a missing-config state even on a fresh database.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1], static::defaults());
    }

    /**
     * Default values mirrored by PaymentSettingsSeeder. Kept here so
     * firstOrCreate() and the seeder stay in sync.
     */
    public static function defaults(): array
    {
        return [
            'business_days_to_pay'  => 2,
            'late_fee_amount'       => 50,
            'method_stripe_enabled' => true,
            'method_wire_enabled'   => true,
            'method_cash_enabled'   => true,
            'method_check_enabled'  => true,
            'payment_location'      => "Colonial Auction Services\n13200 Old Marlboro Pike\nUpper Marlboro, MD 20772",
            'office_hours'          => "Monday – Friday, 9:00 AM – 5:00 PM ET",
            'check_payable_to'      => 'Colonial Auction Services',
            'check_mailing_address' => "Colonial Auction Services\n13200 Old Marlboro Pike\nUpper Marlboro, MD 20772",
            'accepted_carriers'     => ['USPS', 'FedEx', 'UPS', 'DHL', 'Other'],
            'wire_business_address' => "Colonial Auction Services\n13200 Old Marlboro Pike\nUpper Marlboro, MD 20772",
            'payment_deadline_notice' => 'Full payment must be received within two (2) full business days following the auction date.',
            'acknowledgment_text'   => "I acknowledge and understand the payment requirements and release restrictions, including that: payment must be received within two (2) full business days after the auction date; a \$50 late payment fee may be assessed if payment is not received by the deadline; additional penalties, account restrictions, suspension, cancellation of sale, loss of bidding privileges, or collection activity may apply; and that vehicles, titles, gate passes, invoices marked paid, and any related documents will not be released until payment has been received, verified, and approved by Colonial Auction Services.",
        ];
    }
}
