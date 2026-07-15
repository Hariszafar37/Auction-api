<?php

namespace App\Support;

/**
 * Renders {{placeholder}} templates.
 *
 * Deliberately not Blade: this content is admin-authored, and Blade would let a
 * typo (or a malicious paste) execute PHP. Substitution here is a plain string
 * replace over a whitelist — an unknown placeholder resolves to empty, never to
 * an error and never to code.
 */
class NotificationTemplateRenderer
{
    /**
     * Substitute {{vars}} in a single-line string. Unknown or null variables
     * resolve to an empty string.
     *
     * @param array<string, mixed> $variables
     */
    public static function render(?string $template, array $variables): string
    {
        if ($template === null || $template === '') {
            return '';
        }

        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            fn (array $m) => (string) ($variables[$m[1]] ?? ''),
            $template,
        ) ?? '';
    }

    /**
     * Render a multi-line body into the lines a MailMessage should print.
     *
     * A line that contains placeholders and whose placeholders ALL resolve to
     * empty is dropped entirely. That is what preserves the original conditional
     * behaviour of lines like "**Reason:** {{reason}}" and
     * "**Admin Notes:** {{admin_notes}}", which the notification classes used to
     * express with an `if ($this->reason)` guard. Without this rule a missing
     * reason would render a dangling "**Reason:**" label.
     *
     * Lines with no placeholders at all are always kept.
     *
     * @param  array<string, mixed> $variables
     * @return array<int, string>
     */
    public static function renderBodyLines(?string $body, array $variables): array
    {
        if ($body === null || trim($body) === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $body) ?: [];
        $out   = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $line, $matches);
            $placeholders = $matches[1] ?? [];

            if ($placeholders !== []) {
                $allEmpty = true;

                foreach ($placeholders as $name) {
                    $value = $variables[$name] ?? null;
                    if ($value !== null && $value !== '') {
                        $allEmpty = false;
                        break;
                    }
                }

                if ($allEmpty) {
                    continue;
                }
            }

            $rendered = trim(self::render($line, $variables));

            if ($rendered !== '') {
                $out[] = $rendered;
            }
        }

        return $out;
    }
}
