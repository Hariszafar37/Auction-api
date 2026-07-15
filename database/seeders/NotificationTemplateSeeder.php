<?php

namespace Database\Seeders;

use App\Models\NotificationTemplate;
use App\Support\NotificationTemplateDefaults;
use Illuminate\Database\Seeder;

/**
 * Writes the shipped copy for every system notification template.
 *
 * Idempotent, and deliberately non-destructive: existing rows are matched on `key`
 * and only their code-owned columns are refreshed (name/description/variables/
 * supported channels). Admin-authored copy and channel switches are never
 * overwritten — re-running this after a deploy must not silently revert their edits.
 */
class NotificationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach (NotificationTemplateDefaults::all() as $key => $defaults) {
            $existing = NotificationTemplate::query()->where('key', $key)->first();

            if ($existing) {
                $existing->update([
                    'group_key'           => $defaults['group_key'],
                    'notification_type'   => $defaults['notification_type'],
                    'name'                => $defaults['name'],
                    'description'         => $defaults['description'],
                    'available_variables' => $defaults['available_variables'],
                    'supported_channels'  => $defaults['supported_channels'],
                ]);

                continue;
            }

            NotificationTemplate::query()->create([
                'key'            => $key,
                'category'       => 'system',
                'enabled'        => true,
                'email_enabled'  => true,
                'in_app_enabled' => true,
                ...$defaults,
            ]);
        }
    }
}
