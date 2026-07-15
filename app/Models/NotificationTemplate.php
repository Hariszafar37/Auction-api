<?php

namespace App\Models;

use App\Support\NotificationTemplateDefaults;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Editable copy + channel switches for one notification variant.
 *
 * Read with NotificationTemplate::forKey('account_approved.dealer'). A missing row
 * is created from NotificationTemplateDefaults, so the app never hits a
 * missing-config state — the same guarantee PaymentSetting::current() gives.
 */
class NotificationTemplate extends Model
{
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

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
