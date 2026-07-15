<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Resolves an announcement's stored audience descriptor into the users who should
 * receive it, and into human copy for the admin UI.
 *
 * Audience shapes:
 *   ['type' => 'all']
 *   ['type' => 'roles',         'roles' => ['buyer', 'dealer']]
 *   ['type' => 'account_types', 'account_types' => ['individual', 'business']]
 *
 * Only active users are ever targeted — a suspended or blocked account should not
 * receive a broadcast blast.
 */
class AnnouncementAudience
{
    public const TYPES = ['all', 'roles', 'account_types'];

    public const ROLES = ['buyer', 'seller', 'dealer', 'staff', 'admin'];

    public const ACCOUNT_TYPES = ['individual', 'dealer', 'business', 'government'];

    /**
     * A User query scoped to the audience. Callers should chunk it — an
     * announcement can target thousands of users.
     *
     * @param array<string, mixed> $audience
     * @return Builder<User>
     */
    public static function query(array $audience): Builder
    {
        $query = User::query()->where('status', 'active');

        return match ($audience['type'] ?? 'all') {
            'roles'         => $query->role(self::sanitiseRoles($audience['roles'] ?? [])),
            'account_types' => $query->whereIn('account_type', self::sanitiseAccountTypes($audience['account_types'] ?? [])),
            default         => $query,
        };
    }

    /**
     * How many active users the audience currently resolves to. Shown in the
     * composer so an admin sees the blast size before sending.
     *
     * @param array<string, mixed> $audience
     */
    public static function count(array $audience): int
    {
        return self::query($audience)->count();
    }

    /**
     * Human description, e.g. "All active users" or "Buyers, Dealers".
     *
     * @param array<string, mixed> $audience
     */
    public static function describe(array $audience): string
    {
        return match ($audience['type'] ?? 'all') {
            'roles' => 'Roles: ' . self::titleList(self::sanitiseRoles($audience['roles'] ?? [])),
            'account_types' => 'Account types: ' . self::titleList(self::sanitiseAccountTypes($audience['account_types'] ?? [])),
            default => 'All active users',
        };
    }

    /**
     * @param  array<int, string> $roles
     * @return array<int, string>
     */
    private static function sanitiseRoles(array $roles): array
    {
        $clean = array_values(array_intersect($roles, self::ROLES));

        // An empty/garbage roles list must never widen to "everyone" — target no
        // one instead, so a misconfigured audience fails safe.
        return $clean ?: ['__none__'];
    }

    /**
     * @param  array<int, string> $types
     * @return array<int, string>
     */
    private static function sanitiseAccountTypes(array $types): array
    {
        $clean = array_values(array_intersect($types, self::ACCOUNT_TYPES));

        return $clean ?: ['__none__'];
    }

    /**
     * @param array<int, string> $values
     */
    private static function titleList(array $values): string
    {
        $labels = array_map(
            fn (string $v) => ucwords(str_replace('_', ' ', $v)),
            array_filter($values, fn (string $v) => $v !== '__none__'),
        );

        return $labels === [] ? '(none)' : implode(', ', $labels);
    }
}
