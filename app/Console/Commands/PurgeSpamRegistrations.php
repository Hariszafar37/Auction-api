<?php

namespace App\Console\Commands;

use App\Services\Admin\SpamRegistrationScanner;
use Illuminate\Console\Command;

/**
 * CLI front-end for SpamRegistrationScanner. The admin purge console at
 * /admin/spam-registrations drives exactly the same service, so the two cannot
 * disagree about what is safe to delete.
 *
 * Runs as a DRY RUN unless --force is passed: it prints exactly what it would
 * delete and changes nothing.
 */
class PurgeSpamRegistrations extends Command
{
    protected $signature = 'users:purge-spam
                            {--after-id=40 : Only consider users with an id greater than this}
                            {--keep=* : Additional user ids to preserve}
                            {--export= : Write the full candidate list to this CSV path}
                            {--force : Actually delete. Without this the command only reports}';

    protected $description = 'Review and remove spam registrations created by the bot signup campaign';

    public function handle(SpamRegistrationScanner $scanner): int
    {
        $afterId = (int) $this->option('after-id');
        $keep    = array_map('intval', (array) $this->option('keep'));
        $force   = (bool) $this->option('force');

        $this->info("Scanning users with id > {$afterId}" . ($keep ? ' (keeping ' . implode(', ', $keep) . ')' : ''));
        $this->line(sprintf('  %d table(s) count as real activity.', count($scanner->activityTables())));

        $candidates = $scanner->scan($afterId, $keep);

        if ($candidates === []) {
            $this->info('Nothing to do — no users matched.');
            return self::SUCCESS;
        }

        $deletable = array_values(array_filter($candidates, fn (array $row) => $row['deletable']));
        $skipped   = array_values(array_filter($candidates, fn (array $row) => ! $row['deletable']));

        $this->renderReport($deletable, $skipped);

        if ($path = $this->option('export')) {
            $this->export((string) $path, $candidates);
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

        $result = $scanner->purge(array_column($deletable, 'id'), $afterId);

        foreach ($result['skipped'] as $failure) {
            $this->error("  Could not delete user {$failure['id']}: {$failure['reason']}");
        }

        $this->newLine();
        $this->info(sprintf('Deleted %d of %d user(s).', count($result['deleted']), count($deletable)));

        return $result['skipped'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param list<array<string, mixed>> $deletable
     * @param list<array<string, mixed>> $skipped
     */
    private function renderReport(array $deletable, array $skipped): void
    {
        $this->newLine();
        $this->info(sprintf('TO DELETE (%d)', count($deletable)));

        if ($deletable !== []) {
            $this->table(
                ['ID', 'Name', 'Email', 'Status', 'Bot score', 'Registered IP', 'Joined'],
                array_map(fn (array $row) => [
                    $row['id'],
                    mb_strimwidth((string) $row['name'], 0, 30, '…'),
                    mb_strimwidth((string) $row['email'], 0, 32, '…'),
                    $row['status'],
                    $row['name_score'] . ($row['looks_automated'] ? ' ⚑' : ''),
                    $row['registration_ip_address'] ?? '—',
                    substr((string) $row['created_at'], 0, 16),
                ], $deletable),
            );
        }

        if ($skipped !== []) {
            $this->newLine();
            $this->warn(sprintf('SKIPPED — has real history (%d)', count($skipped)));
            $this->table(
                ['ID', 'Email', 'Reason'],
                array_map(fn (array $row) => [
                    $row['id'],
                    mb_strimwidth((string) $row['email'], 0, 40, '…'),
                    implode(', ', $row['blockers']),
                ], $skipped),
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $candidates
     */
    private function export(string $path, array $candidates): void
    {
        $handle = fopen($path, 'w');

        if ($handle === false) {
            $this->error("Could not open {$path} for writing.");
            return;
        }

        fputcsv($handle, [
            'action', 'id', 'name', 'email', 'status',
            'name_score', 'registration_ip', 'created_at', 'reason',
        ]);

        foreach ($candidates as $row) {
            fputcsv($handle, [
                $row['deletable'] ? 'delete' : 'keep',
                $row['id'],
                $row['name'],
                $row['email'],
                $row['status'],
                $row['name_score'],
                $row['registration_ip_address'],
                $row['created_at'],
                implode(', ', $row['blockers']),
            ]);
        }

        fclose($handle);
        $this->info("Full list written to {$path}");
    }
}
