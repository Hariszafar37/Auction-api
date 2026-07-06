<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable audit row for an administrative account action taken against a
 * user (suspend / block / reactivate / bidding & selling toggles).
 *
 * Deliberately separate from ApprovalHistory: that log is the record of the
 * dealer/business/seller/gov/POA approval workflows and feeds the Approval
 * Dashboard. Account restrictions are a different domain and would otherwise
 * pollute the approval history feed and its type filter.
 */
class AccountAction extends Model
{
    protected $table = 'account_actions';

    // Status transitions
    public const ACTION_SUSPENDED   = 'suspended';
    public const ACTION_BLOCKED     = 'blocked';
    public const ACTION_REACTIVATED = 'reactivated';
    public const ACTION_STATUS_CHANGED = 'status_changed';

    // Capability toggles
    public const ACTION_BIDDING_DISABLED = 'bidding_disabled';
    public const ACTION_BIDDING_ENABLED  = 'bidding_enabled';
    public const ACTION_SELLING_DISABLED = 'selling_disabled';
    public const ACTION_SELLING_ENABLED  = 'selling_enabled';

    /**
     * Human-friendly labels for each stored action value. The DB keeps the raw
     * snake_case value; this is the canonical display label used by the CSV
     * export (the frontend mirrors this map for its badges).
     */
    public const LABELS = [
        self::ACTION_SUSPENDED        => 'Account Suspended',
        self::ACTION_BLOCKED          => 'Account Blocked',
        self::ACTION_REACTIVATED      => 'Account Reactivated',
        self::ACTION_STATUS_CHANGED   => 'Status Changed',
        self::ACTION_BIDDING_DISABLED => 'Bidding Disabled',
        self::ACTION_BIDDING_ENABLED  => 'Bidding Enabled',
        self::ACTION_SELLING_DISABLED => 'Selling Disabled',
        self::ACTION_SELLING_ENABLED  => 'Selling Enabled',
    ];

    public static function label(string $action): string
    {
        return self::LABELS[$action] ?? ucwords(str_replace('_', ' ', $action));
    }

    protected $fillable = [
        'subject_user_id',
        'action',
        'previous_value',
        'new_value',
        'reason',
        'performed_by',
        'ip_address',
        'user_agent',
        'performed_at',
    ];

    protected function casts(): array
    {
        return [
            'performed_at' => 'datetime',
        ];
    }

    public function subjectUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
