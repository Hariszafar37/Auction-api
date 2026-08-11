<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the accounts created by the registration list-bombing campaign.
 *
 * Runs as a DRY RUN unless --force is passed: it prints exactly what it would
 * delete and changes nothing. Nothing is ever removed without a second,
 * explicit invocation.
 *
 * Deleting production users is irreversible, so the safety model is
 * deny-by-default. The command discovers every table holding a foreign key to
 * `users` straight from the live schema, and any user with a row in a table
 * outside the allowlist below is SKIPPED and reported rather than deleted. A
 * table added by a future migration therefore blocks deletion automatically
 * instead of being silently cascaded away.
 */
class PurgeSpamRegistrations extends Command
{
    protected $signature = 'users:purge-spam
                            {--after-id=40 : Only consider users with an id greater than this}
                            {--keep=* : Additional user ids to preserve}
                            {--export= : Write the full candidate list to this CSV path}
                            {--force : Actually delete. Without this the command only reports}';

    protected $description = 'Review and remove spam registrations created by the bot signup campaign';

    /**
     * Tables that hold nothing but the account scaffolding itself. Rows here are
     * expected on any account, spam or not, and do not indicate real activity.
     *
     * Anything NOT listed here — bids, invoices, vehicles, disputes, documents,
     * power of attorney, settlements — marks the user as having real history and
     * takes them out of scope entirely.
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

    public function handle(): int
    {
        $afterId = (int) $this->option('after-id');
        $keep    = array_map('intval', (array) $this->option('keep'));
        $force   = (bool) $this->option('force');

        $this->info("Scanning users with id > {$afterId}" . ($keep ? ' (keeping ' . implode(', ', $keep) . ')' : ''));

        $referencingTables = $this->tablesReferencingUsers();
        $activityTables    = array_diff_key($referencingTables, array_flip(self::ACCOUNT_SCAFFOLDING));

        $this->line(sprintf(
            '  %d table(s) reference users; %d of them count as real activity.',
            count($referencingTables),
            count($activityTables),
        ));

        /** @var Collection<int, User> $candidates */
        $candidates = User::query()
            ->where('id', '>', $afterId)
            ->when($keep, fn ($q) => $q->whereNotIn('id', $keep))
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('Nothing to do — no users matched.');
            return self::SUCCESS;
        }

        $deletable = [];
        $skipped   = [];

        foreach ($candidates as $user) {
            // Never touch privileged accounts, whatever their id.
            if ($user->hasAnyRole(['admin', 'staff'])) {
                $skipped[] = [$user, 'privileged role'];
                continue;
            }

            $activity = $this->activityFor($user, $activityTables);

            if ($activity !== []) {
                $skipped[] = [$user, implode(', ', $activity)];
                continue;
            }

            $deletable[] = $user;
        }

        $this->renderReport($deletable, $skipped);

        if ($path = $this->option('export')) {
            $this->export((string) $path, $deletable, $skipped);
        }

        if ($deletable === []) {
            $this->info('No user qualifies for deletion.');
            return self::SUCCESS;
        }

        if (! $force) {
            $this->newLine();
            $this->warn('DRY RUN — nothing has been deleted.');
            $this->line('Review the list above, then re-run with --force to delete:');
            $this->line("  php artisan users:purge-spam --after-id={$afterId} --force");
            return self::SUCCESS;
        }

        if ($this->input->isInteractive()
            && ! $this->confirm(sprintf('Permanently delete %d user(s)? This cannot be undone.', count($deletable)))) {
            $this->info('Aborted. Nothing was deleted.');
            return self::SUCCESS;
        }

        return $this->delete($deletable);
    }

    /**
     * Every table with a foreign key pointing at users, read from the live schema
     * rather than hardcoded, mapped to the columns that reference it.
     *
     * MySQL answers this in a single information_schema query. Everything else —
     * notably the SQLite database the test suite runs on — falls back to
     * per-table introspection, which is correct but far too slow to use against
     * a production schema of this size.
     *
     * @return array<string, list<string>>
     */
    private function tablesReferencingUsers(): array
    {
        return DB::connection()->getDriverName() === 'mysql'
            ? $this->tablesReferencingUsersViaInformationSchema()
            : $this->tablesReferencingUsersViaIntrospection();
    }

    /**
     * @return array<string, list<string>>
     */
    private function tablesReferencingUsersViaInformationSchema(): array
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
     * @return array<string, list<string>>
     */
    private function tablesReferencingUsersViaIntrospection(): array
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

    /**
     * @param  array<string, list<string>> $activityTables
     * @return list<string> human-readable descriptions of what this user has done
     */
    private function activityFor(User $user, array $activityTables): array
    {
        $found = [];

        foreach ($activityTables as $table => $columns) {
            $count = DB::table($table)
                ->where(function ($query) use ($columns, $user) {
                    foreach ($columns as $column) {
                        $query->orWhere($column, $user->id);
                    }
                })
                ->count();

            if ($count > 0) {
                $found[] = "{$table}({$count})";
            }
        }

        return $found;
    }

    /**
     * @param list<User>                $deletable
     * @param list<array{0:User,1:string}> $skipped
     */
    private function renderReport(array $deletable, array $skipped): void
    {
        $this->newLine();
        $this->info(sprintf('TO DELETE (%d)', count($deletable)));

        if ($deletable !== []) {
            $this->table(
                ['ID', 'Name', 'Email', 'Status', 'Registered IP', 'Joined'],
                array_map(fn (User $u) => [
                    $u->id,
                    mb_strimwidth((string) $u->name, 0, 34, '…'),
                    mb_strimwidth((string) $u->email, 0, 34, '…'),
                    $u->status,
                    $u->registration_ip_address ?? '—',
                    optional($u->created_at)->format('Y-m-d H:i') ?? '—',
                ], $deletable),
            );
        }

        if ($skipped !== []) {
            $this->newLine();
            $this->warn(sprintf('SKIPPED — has real history (%d)', count($skipped)));
            $this->table(
                ['ID', 'Email', 'Reason'],
                array_map(fn (array $row) => [
                    $row[0]->id,
                    mb_strimwidth((string) $row[0]->email, 0, 40, '…'),
                    $row[1],
                ], $skipped),
            );
        }
    }

    /**
     * @param list<User>                   $deletable
     * @param list<array{0:User,1:string}> $skipped
     */
    private function export(string $path, array $deletable, array $skipped): void
    {
        $handle = fopen($path, 'w');

        if ($handle === false) {
            $this->error("Could not open {$path} for writing.");
            return;
        }

        fputcsv($handle, ['action', 'id', 'name', 'email', 'status', 'registration_ip', 'created_at', 'reason']);

        foreach ($deletable as $user) {
            fputcsv($handle, [
                'delete', $user->id, $user->name, $user->email, $user->status,
                $user->registration_ip_address, optional($user->created_at)->toIso8601String(), '',
            ]);
        }

        foreach ($skipped as [$user, $reason]) {
            fputcsv($handle, [
                'keep', $user->id, $user->name, $user->email, $user->status,
                $user->registration_ip_address, optional($user->created_at)->toIso8601String(), $reason,
            ]);
        }

        fclose($handle);
        $this->info("Full list written to {$path}");
    }

    /**
     * @param list<User> $deletable
     */
    private function delete(array $deletable): int
    {
        $ids     = array_map(fn (User $u) => $u->id, $deletable);
        $deleted = 0;

        // One transaction per user rather than one for the whole batch: if a
        // foreign key we did not anticipate rejects a delete, that single user is
        // rolled back and reported while the rest of the purge still completes.
        foreach ($deletable as $user) {
            try {
                DB::transaction(function () use ($user) {
                    $user->tokens()->delete();
                    $user->syncRoles([]);
                    $user->delete();
                });

                $deleted++;
            } catch (\Throwable $e) {
                $this->error("  Could not delete user {$user->id} ({$user->email}): {$e->getMessage()}");
            }
        }

        Log::warning('bot_guard: spam registrations purged', [
            'requested' => count($ids),
            'deleted'   => $deleted,
            'ids'       => $ids,
        ]);

        $this->newLine();
        $this->info("Deleted {$deleted} of " . count($ids) . ' user(s).');

        return $deleted === count($ids) ? self::SUCCESS : self::FAILURE;
    }
}
