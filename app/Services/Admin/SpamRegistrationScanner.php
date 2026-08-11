<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Support\BotSignals;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Finds and removes the accounts created by the registration list-bombing
 * campaign. Shared by `users:purge-spam` and the admin purge console so the two
 * can never drift apart on what counts as safe to delete.
 *
 * Deleting production users is irreversible, so the safety model is
 * deny-by-default:
 *
 *  - Only ids above a boundary are ever considered (the operator confirmed
 *    everything up to and including id 40 is genuine).
 *  - The tables holding a foreign key to `users` are discovered from the LIVE
 *    schema, not hardcoded. Any user with a row in one of them, outside a small
 *    account-scaffolding allowlist, is refused. A table added by a future
 *    migration therefore blocks deletion automatically rather than being
 *    silently cascaded away.
 *  - Accounts holding a privileged role are never touched, whatever their id.
 *
 * purge() re-derives all of that from scratch for every id it is handed. It
 * never trusts a caller's assertion that a user is safe to remove — which is
 * what makes it safe to expose over HTTP.
 */
class SpamRegistrationScanner
{
    /**
     * Everything at or below this id predates the attack and is genuine.
     */
    public const DEFAULT_BOUNDARY = 40;

    /**
     * Tables holding nothing but the account scaffolding itself. Rows here are
     * expected on any account and do not indicate real activity.
     *
     * Anything NOT listed — bids, invoices, vehicles, disputes, documents,
     * power of attorney, settlements — marks the user as having real history.
     */
    private const ACCOUNT_SCAFFOLDING = [
        'buyer_profiles',
        'dealer_profiles',
        'business_profiles',
        'seller_profiles',
        'gov_profiles',
        'user_account_information',
        'user_dealer_information',
        'user_billing_information',
        'user_business_information',
        'account_actions',
        'notifications',
        'personal_access_tokens',
        'sessions',
    ];

    private const PRIVILEGED_ROLES = ['admin', 'staff'];

    /** @var array<string, list<string>>|null */
    private ?array $activityTables = null;

    /**
     * Assess every account above the boundary.
     *
     * @param  list<int> $keep ids the operator wants preserved regardless
     * @return list<array<string, mixed>>
     */
    public function scan(int $afterId = self::DEFAULT_BOUNDARY, array $keep = []): array
    {
        /** @var Collection<int, User> $users */
        $users = User::query()
            ->where('id', '>', $afterId)
            ->with('roles')
            ->orderBy('id')
            ->get();

        if ($users->isEmpty()) {
            return [];
        }

        $blockers = $this->activityBlockersFor($users);

        return $users->map(function (User $user) use ($blockers, $keep): array {
            $reasons = $blockers[$user->id] ?? [];

            if ($user->hasAnyRole(self::PRIVILEGED_ROLES)) {
                array_unshift($reasons, 'privileged role');
            }

            if (in_array($user->id, $keep, true)) {
                array_unshift($reasons, 'explicitly kept');
            }

            return [
                'id'                      => $user->id,
                'name'                    => $user->name,
                'first_name'              => $user->first_name,
                'last_name'               => $user->last_name,
                'email'                   => $user->email,
                'status'                  => $user->status,
                'registration_ip_address' => $user->registration_ip_address,
                'created_at'              => optional($user->created_at)->toIso8601String(),

                // Surfaced so a reviewer can see WHY a row looks automated rather
                // than taking the verdict on faith.
                'name_score'              => $this->worstNameScore($user),
                'looks_automated'         => $this->looksAutomated($user),

                'deletable'               => $reasons === [],
                'blockers'                => array_values($reasons),
            ];
        })->all();
    }

    /**
     * Delete the given users, re-deriving eligibility for each one.
     *
     * Ids that fail any safety check are reported as skipped rather than
     * silently ignored, so a caller can always account for every id it sent.
     *
     * @param  list<int> $userIds
     * @return array{deleted: list<int>, skipped: list<array{id: int, reason: string}>}
     */
    public function purge(array $userIds, int $afterId = self::DEFAULT_BOUNDARY, ?int $actorId = null): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        if ($userIds === []) {
            return ['deleted' => [], 'skipped' => []];
        }

        // Re-derive the full assessment rather than trusting the caller's list.
        $assessment = collect($this->scan($afterId))->keyBy('id');

        $deleted = [];
        $skipped = [];

        foreach ($userIds as $id) {
            $row = $assessment->get($id);

            if ($row === null) {
                $skipped[] = ['id' => $id, 'reason' => 'not eligible for review'];
                continue;
            }

            if (! $row['deletable']) {
                $skipped[] = ['id' => $id, 'reason' => implode(', ', $row['blockers'])];
                continue;
            }

            $user = User::find($id);

            if ($user === null) {
                $skipped[] = ['id' => $id, 'reason' => 'already removed'];
                continue;
            }

            try {
                // One transaction per user: an unanticipated foreign key rejects
                // that single user and is reported, while the rest still complete.
                DB::transaction(function () use ($user) {
                    $user->tokens()->delete();
                    $user->syncRoles([]);
                    $user->delete();
                });

                $deleted[] = $id;
            } catch (\Throwable $e) {
                $skipped[] = ['id' => $id, 'reason' => 'delete failed: ' . $e->getMessage()];
            }
        }

        Log::warning('bot_guard: spam registrations purged', [
            'actor_id'  => $actorId,
            'requested' => count($userIds),
            'deleted'   => $deleted,
            'skipped'   => $skipped,
        ]);

        return ['deleted' => $deleted, 'skipped' => $skipped];
    }

    /**
     * Which of the given users have real history, and where.
     *
     * Queries once per activity table with a whereIn rather than once per user,
     * so the cost is bounded by the number of tables instead of the number of
     * accounts under review.
     *
     * @param  Collection<int, User> $users
     * @return array<int, list<string>> user id => descriptions
     */
    private function activityBlockersFor(Collection $users): array
    {
        $ids      = $users->pluck('id')->all();
        $blockers = [];

        foreach ($this->activityTables() as $table => $columns) {
            foreach ($columns as $column) {
                $hits = DB::table($table)
                    ->select($column)
                    ->whereIn($column, $ids)
                    ->groupBy($column)
                    ->pluck($column);

                foreach ($hits as $userId) {
                    $blockers[(int) $userId][] = $table;
                }
            }
        }

        return array_map(
            static fn (array $tables): array => array_values(array_unique($tables)),
            $blockers,
        );
    }

    /**
     * Every table with a foreign key pointing at users, read from the live
     * schema, minus the account-scaffolding allowlist.
     *
     * @return array<string, list<string>>
     */
    public function activityTables(): array
    {
        if ($this->activityTables !== null) {
            return $this->activityTables;
        }

        $referencing = DB::connection()->getDriverName() === 'mysql'
            ? $this->referencingTablesViaInformationSchema()
            : $this->referencingTablesViaIntrospection();

        return $this->activityTables = array_diff_key(
            $referencing,
            array_flip(self::ACCOUNT_SCAFFOLDING),
        );
    }

    /**
     * MySQL answers this in a single query. Per-table introspection is correct
     * but far too slow against a production schema of this size.
     *
     * @return array<string, list<string>>
     */
    private function referencingTablesViaInformationSchema(): array
    {
        $rows = DB::select(
            'SELECT TABLE_NAME AS `table_name`, COLUMN_NAME AS `column_name`
               FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE()
                AND REFERENCED_TABLE_NAME = ?
                AND TABLE_NAME <> ?',
            ['users', 'users'],
        );

        $map = [];

        foreach ($rows as $row) {
            $map[$row->table_name][] = $row->column_name;
        }

        return array_map('array_unique', $map);
    }

    /**
     * Portable fallback — notably for the SQLite database the tests run on.
     *
     * @return array<string, list<string>>
     */
    private function referencingTablesViaIntrospection(): array
    {
        $map = [];

        foreach (Schema::getTableListing() as $table) {
            // Some drivers report schema-qualified names ("main.users").
            $table = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;

            if ($table === 'users') {
                continue;
            }

            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                if (($foreignKey['foreign_table'] ?? null) !== 'users') {
                    continue;
                }

                foreach ($foreignKey['columns'] ?? [] as $column) {
                    $map[$table][] = $column;
                }
            }
        }

        return array_map('array_unique', $map);
    }

    private function worstNameScore(User $user): int
    {
        return max(
            BotSignals::nameScore((string) $user->first_name),
            BotSignals::nameScore((string) $user->last_name),
        );
    }

    private function looksAutomated(User $user): bool
    {
        return BotSignals::looksMachineGenerated((string) $user->first_name)
            || BotSignals::looksMachineGenerated((string) $user->last_name);
    }
}
