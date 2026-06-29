<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Master Auction Entry Terms & Conditions (versioned).
 *
 * The newest row with is_current = true is the live document. Updating the
 * terms creates a NEW row at the next minor version and retires the previous
 * current row, preserving full history for acceptance auditing.
 */
class AuctionTerm extends Model
{
    protected $fillable = [
        'version',
        'header',
        'intro',
        'important_information',
        'full_terms_content',
        'checkbox_label',
        'fees_url',
        'payment_policy_url',
        'is_current',
        'created_by',
    ];

    protected $casts = [
        'important_information' => 'array',
        'is_current'           => 'boolean',
    ];

    public function acceptances(): HasMany
    {
        return $this->hasMany(AuctionTermAcceptance::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The live terms document. Seeded with defaults on first access so the gate
     * never hits a missing-config state, even on a fresh database.
     */
    public static function current(): self
    {
        $current = static::query()->where('is_current', true)->latest('id')->first();

        if ($current) {
            return $current;
        }

        return static::query()->create(array_merge(static::defaults(), [
            'version'    => '1.0',
            'is_current' => true,
        ]));
    }

    /**
     * Publish an updated document. Wrapped in a transaction: the previous
     * current row is retired and a fresh row is created at the next minor
     * version so existing acceptances keep referencing the exact text agreed to.
     */
    public static function publishUpdate(array $attributes, ?int $userId = null): self
    {
        return DB::transaction(function () use ($attributes, $userId) {
            $previous = static::current();

            static::query()->where('is_current', true)->update(['is_current' => false]);

            return static::query()->create(array_merge(
                static::defaults(),
                $previous->only([
                    'header', 'intro', 'important_information', 'full_terms_content',
                    'checkbox_label', 'fees_url', 'payment_policy_url',
                ]),
                $attributes,
                [
                    'version'    => static::nextVersion($previous->version),
                    'is_current' => true,
                    'created_by' => $userId,
                ],
            ));
        });
    }

    /**
     * Auto-increment the minor component: "1.0" → "1.1" → "1.2". Admins never
     * type versions, which avoids duplicates and ordering mistakes.
     */
    public static function nextVersion(string $current): string
    {
        $parts = explode('.', $current);
        $major = (int) ($parts[0] ?? 1);
        $minor = (int) ($parts[1] ?? 0);

        return $major . '.' . ($minor + 1);
    }

    /**
     * Seed content for v1.0 — sourced from the client's "TERMS & CONDITIONS
     * BREAKDOWN" document. Kept here so current() and AuctionTermsSeeder stay
     * in sync.
     */
    public static function defaults(): array
    {
        return [
            'header'        => 'Enter Auction',
            'intro'         => 'Please review and acknowledge the following information before participating.',
            'important_information' => [
                'All vehicles and items are sold AS-IS, WHERE-IS, with no warranties expressed or implied.',
                'All sales are final.',
                'You are responsible for reviewing all vehicle descriptions, photos, announcements, and disclosures before bidding.',
                'A bid is a legally binding commitment to purchase.',
                'If you place bids on multiple vehicles and become the winning bidder on multiple vehicles, you are obligated to complete the purchase of every vehicle won.',
                'Winning bidders must pay all applicable fees, taxes, deposits, and auction charges.',
                'Failure to complete payment may result in account suspension, cancellation of bidding privileges, forfeiture of deposits, fees, and/or legal action.',
                'Colonial Auction Services reserves the right to refuse service, suspend accounts, reject bids, remove participants, or cancel transactions when necessary.',
                'Vehicle condition reports are provided as a courtesy and are not guarantees.',
                'Announcements made by Colonial Auction Services supersede written descriptions when applicable.',
                'By proceeding, you agree to abide by all auction rules, payment requirements, pickup deadlines, and terms governing participation.',
            ],
            'full_terms_content' => static::seedFullTerms(),
            'checkbox_label' => 'I have read, understand, and agree to the Auction Terms & Conditions.',
            'fees_url'           => null,
            'payment_policy_url' => null,
        ];
    }

    /**
     * The complete Terms & Conditions body shown behind "View Full Terms &
     * Conditions". Stored as the v1.0 seed; admins can edit it thereafter.
     */
    private static function seedFullTerms(): string
    {
        return <<<'TERMS'
COLONIAL AUCTION SERVICES — AUCTION TERMS & CONDITIONS

1. ACCEPTANCE OF TERMS
By registering for, accessing, viewing, entering, bidding in, purchasing from, selling through, or otherwise participating in any auction conducted by Colonial Auction Services ("CAS"), you acknowledge that you have read, understood, and agree to be bound by these Terms & Conditions, as amended from time to time. Electronic acceptance of these Terms shall have the same force and effect as a handwritten signature.

2. ELIGIBILITY
Participants must: be at least eighteen (18) years of age; provide accurate and complete registration information; successfully complete any identity verification requirements requested by CAS; maintain a valid payment method on file; and comply with all applicable federal, state, and local laws. CAS reserves the right to approve, deny, suspend, or terminate any account at its sole discretion.

3. AS-IS / WHERE-IS SALES
All vehicles, equipment, assets, and items are sold AS-IS, WHERE-IS, WITH ALL FAULTS. No warranties, guarantees, or representations are made by CAS regarding mechanical condition, cosmetic condition, safety condition, roadworthiness, mileage, odometer accuracy, vehicle history, emissions compliance, title status, fitness for a particular purpose, or merchantability. All sales are final. No refunds, exchanges, credits, returns, or cancellations shall be permitted unless required by law.

4. VEHICLE INFORMATION
Vehicle descriptions, photos, condition reports, VIN decodes, disclosures, announcements, and other information are provided solely as a convenience. CAS does not warrant or guarantee the accuracy, completeness, or reliability of any information provided. Buyers are solely responsible for conducting their own inspections, research, and due diligence prior to bidding.

5. AUCTION ANNOUNCEMENTS
Any verbal, written, electronic, or posted announcement made by CAS before or during an auction shall take precedence over previously published information. CAS reserves the right to correct errors, omissions, inaccuracies, and clerical mistakes at any time.

6. BIDDING AGREEMENT
Every bid constitutes a legally binding offer, an agreement to purchase if declared the winning bidder, and acceptance of all auction rules and requirements. Bids may not be canceled, withdrawn, deleted, revoked, or retracted. Participants are responsible for all bids submitted through their account.

7. MULTIPLE VEHICLE OBLIGATION
If a bidder places bids on multiple vehicles and becomes the winning bidder on multiple vehicles, the bidder is legally obligated to complete the purchase of EVERY vehicle won. Winning multiple vehicles does not relieve the bidder of responsibility for any individual purchase.

8. PROXY BIDDING
CAS may provide proxy bidding functionality. Participants acknowledge that the system may automatically increase bids on their behalf, proxy bids may compete against other proxy bids, and automated bids generated by the platform are valid and binding. CAS is not responsible for a participant's selected maximum bid amount.

9. AUCTION EXTENSIONS
CAS may extend auction closing times when bids are placed near the scheduled end time. Auction extensions are final and binding. CAS reserves the right to modify auction closing times when necessary.

10. DEPOSITS
CAS may require deposits before bidding and/or automatically charge deposits after a winning bid. Deposits may range from $300.00 to $500.00 per vehicle won, or such other amount as determined by CAS. Deposits may be applied toward the final purchase price. Failure to complete payment may result in forfeiture of deposits. Deposits are generally non-refundable unless otherwise required by law.

11. PAYMENT TERMS
Payment in full must be received within two (2) business days following the close of the auction unless otherwise stated by CAS. Accepted payment methods may include credit card, debit card, bank wire, cash, cashier's check, or other methods approved by CAS. Personal checks and business checks may be refused at CAS's discretion.

12. LATE FEES
Failure to pay within the required payment period may result in late fees, storage fees, account suspension, revocation of bidding privileges, deposit forfeiture, and/or cancellation of the sale. CAS may assess a late fee of fifty dollars ($50.00) per day, or such other fee disclosed by CAS, until payment is received.

13. DEFAULT AND FAILURE TO PAY
If payment is not received within the required timeframe, CAS may cancel the sale, retain all deposits, resell the vehicle, and suspend or terminate the bidder's account and privileges. The defaulting bidder remains responsible for any deficiency and associated costs.
TERMS;
    }
}
