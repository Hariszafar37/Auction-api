<?php

namespace App\Support;

use App\Models\User;

/**
 * Single source of truth for who may reach the spam purge console.
 *
 * Used by the middleware that guards the endpoints AND by UserResource, so the
 * navigation the browser draws can never disagree with what the API will
 * actually allow. Hiding the menu item is presentation; this is enforcement.
 *
 * See config/bot_guard.php for why matching is on email rather than user id.
 */
final class SpamPurgeAccess
{
    public static function allows(?User $user): bool
    {
        if ($user === null || $user->email === null) {
            return false;
        }

        $allowed = self::allowlist();

        // Fail closed. An empty or mistyped allowlist locks everyone out rather
        // than falling through to "no restriction configured, let them in".
        if ($allowed === []) {
            return false;
        }

        return in_array(self::normalise($user->email), $allowed, true);
    }

    /**
     * @return list<string> normalised allowed addresses
     */
    private static function allowlist(): array
    {
        $raw = (string) config('bot_guard.purge_allowlist', '');

        return array_values(array_filter(
            array_map(self::normalise(...), explode(',', $raw)),
            static fn (string $email): bool => $email !== '',
        ));
    }

    private static function normalise(string $email): string
    {
        // Case-insensitive and whitespace-tolerant so a stray space in the env
        // var does not quietly lock out the one person who needs this.
        //
        // Note: '+' tags are NOT stripped. "user+10@x.com" and "user@x.com" are
        // different mailboxes as far as this gate is concerned, because the
        // allowlisted identity is the specific tagged address.
        return mb_strtolower(trim($email));
    }
}
