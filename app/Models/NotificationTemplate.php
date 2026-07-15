<?php

namespace App\Models;

use App\Support\NotificationTemplateDefaults;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Editable copy + channel switches for a notification.
 *
 * Two categories share this table:
 *   - 'system'       — one persistent row per notification the app fires. Read with
 *                      forKey('account_approved.dealer'); a missing row seeds itself
 *                      from NotificationTemplateDefaults.
 *   - 'announcement' — an admin-composed message sent manually to an audience. Has a
 *                      generated key, an `audience`, and a `sent_at` (null = draft).
 */
class NotificationTemplate extends Model
{
    public const CATEGORY_SYSTEM       = 'system';
    public const CATEGORY_ANNOUNCEMENT = 'announcement';
    protected $fillable = [
        'key',
        'group_key',
        'notification_type',
        'name',
        'description',
        'category',
        'enabled',
        'email_enabled',
        'in_app_enabled',
        'subject',
        'greeting',
        'email_body',
        'action_label',
        'title',
        'message',
        'available_variables',
        'supported_channels',
        'audience',
        'sent_at',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'enabled'             => 'boolean',
            'email_enabled'       => 'boolean',
            'in_app_enabled'      => 'boolean',
            'available_variables' => 'array',
            'supported_channels'  => 'array',
            'audience'            => 'array',
            'sent_at'             => 'datetime',
        ];
    }

    /**
     * The template for a key, seeded from defaults on first access.
     *
     * Not statically cached: a stale instance would outlive the row it came from
     * (rolled-back test transactions, long-lived queue workers). Callers that send
     * to many notifiables already memoise this per notification instance — see
     * RendersFromTemplate::template().
     *
     * @throws \InvalidArgumentException when the key is not a known system template
     */
    public static function forKey(string $key): self
    {
        $existing = static::query()->where('key', $key)->first();

        if ($existing) {
            return $existing;
        }

        $defaults = NotificationTemplateDefaults::get($key);

        if ($defaults === null) {
            throw new \InvalidArgumentException("Unknown notification template key [{$key}].");
        }

        return static::query()->create([
            'key'      => $key,
            'category' => 'system',
            // Set the switches explicitly rather than leaning on the column
            // defaults: create() returns a model carrying only the attributes we
            // passed, so an omitted `enabled` would read back as null and
            // activeChannels() would report the type as switched off.
            'enabled'        => true,
            'email_enabled'  => true,
            'in_app_enabled' => true,
            ...$defaults,
        ]);
    }

    /**
     * The channels this template should actually send on: the intersection of
     * what the calling code supports and what the admin left switched on.
     *
     * in_app_enabled covers 'database' and 'broadcast' together — the persisted
     * row and its realtime push are one feature to a user.
     *
     * @return array<int, string>
     */
    public function activeChannels(): array
    {
        if (! $this->enabled) {
            return [];
        }

        $supported = $this->supported_channels ?? NotificationTemplateDefaults::CHANNELS_ALL;
        $channels  = [];

        if ($this->email_enabled && in_array('mail', $supported, true)) {
            $channels[] = 'mail';
        }

        if ($this->in_app_enabled) {
            foreach (['database', 'broadcast'] as $channel) {
                if (in_array($channel, $supported, true)) {
                    $channels[] = $channel;
                }
            }
        }

        return $channels;
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /** @param Builder<self> $query */
    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('category', self::CATEGORY_SYSTEM);
    }

    /** @param Builder<self> $query */
    public function scopeAnnouncements(Builder $query): Builder
    {
        return $query->where('category', self::CATEGORY_ANNOUNCEMENT);
    }

    // ── Announcements ─────────────────────────────────────────────────────────

    public function isAnnouncement(): bool
    {
        return $this->category === self::CATEGORY_ANNOUNCEMENT;
    }

    /** A draft announcement has not been sent yet, so it may still be edited or deleted. */
    public function isDraft(): bool
    {
        return $this->isAnnouncement() && $this->sent_at === null;
    }

    /**
     * Create a draft announcement. All fields are content the admin supplies; the
     * key is generated (announcements are not looked up by a stable key like system
     * templates are), and channels default to all-on.
     *
     * @param array<string, mixed> $attributes
     */
    public static function createAnnouncement(array $attributes): self
    {
        return static::query()->create([
            'key'                 => 'announcement.' . Str::ulid(),
            'group_key'           => self::CATEGORY_ANNOUNCEMENT,
            'notification_type'   => self::CATEGORY_ANNOUNCEMENT,
            'category'            => self::CATEGORY_ANNOUNCEMENT,
            'enabled'             => true,
            'email_enabled'       => true,
            'in_app_enabled'      => true,
            'available_variables' => NotificationTemplateDefaults::COMMON_VARIABLES,
            'supported_channels'  => NotificationTemplateDefaults::CHANNELS_ALL,
            ...$attributes,
        ]);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
