<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Limits;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Daily retention prune. Port of the Node backend's cleanupOldLogs().
 *
 * Runs from the scheduler (see routes/console.php). Six tables get pruned
 * to keep the SQLite file from growing unbounded:
 *
 *   request_logs        — 30 days
 *   panel_opens         — 30 days
 *   usage               — 30 days
 *   webhook_events      — 30 days (LS retry envelope is ~24h)
 *   email_verifications — 7 days past expiry
 *   email_outbox        — 30 days
 *
 * All retention values live in App\Support\Limits — change once, applies on
 * the next nightly run.
 *
 * Idempotent. Safe to run on cron AND manually. The DELETE statements use
 * indexed columns (date / created_at / queued_at / received_at) so the
 * cleanup runs in seconds even with millions of rows.
 *
 * Usage:
 *   php artisan verdict:cleanup-logs
 *   php artisan verdict:cleanup-logs --dry-run    (counts but doesn't delete)
 */
final class CleanupLogsCommand extends Command
{
    protected $signature = 'verdict:cleanup-logs
                            {--dry-run : Count rows that would be deleted without deleting them}';

    protected $description = 'Prune old request logs, usage, panel opens, webhook events, and email rows';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = $this->utcDateDaysAgo(Limits::LOG_RETENTION_DAYS);

        $this->info($dryRun ? '[DRY RUN] Counting rows to delete...' : 'Pruning old rows...');
        $this->line("Cutoff date: {$cutoff}");

        $tally = [
            'request_logs'        => 0,
            'panel_opens'         => 0,
            'usage'               => 0,
            'webhook_events'      => 0,
            'email_verifications' => 0,
            'email_outbox'        => 0,
        ];

        try {
            $tally['request_logs']   = $this->prune('DELETE FROM request_logs WHERE created_at < ?',   [$cutoff], $dryRun, 'request_logs', 'created_at');
            $tally['panel_opens']    = $this->prune('DELETE FROM panel_opens   WHERE date < ?',        [$cutoff], $dryRun, 'panel_opens',   'date');
            $tally['usage']          = $this->prune('DELETE FROM usage         WHERE date < ?',        [$cutoff], $dryRun, 'usage',         'date');
            $tally['webhook_events'] = $this->prune('DELETE FROM webhook_events WHERE received_at < ?', [$cutoff], $dryRun, 'webhook_events', 'received_at');
            $tally['email_outbox']   = $this->prune('DELETE FROM email_outbox   WHERE queued_at < ?',  [$cutoff], $dryRun, 'email_outbox',   'queued_at');

            // email_verifications use a different rule: drop 7 days past expiry
            // (which is independent of when the code was created). 7 days is
            // long enough to investigate disputed restore attempts; longer
            // would just bloat the table.
            $verifSql = "DELETE FROM email_verifications WHERE datetime(expires_at, '+7 days') < datetime('now')";
            if ($dryRun) {
                $tally['email_verifications'] = (int) DB::scalar(
                    "SELECT COUNT(*) FROM email_verifications WHERE datetime(expires_at, '+7 days') < datetime('now')"
                );
            } else {
                $tally['email_verifications'] = DB::affectingStatement($verifSql);
            }
            $this->line("email_verifications: " . ($dryRun ? "would delete " : "deleted ") . $tally['email_verifications']);

        } catch (Throwable $e) {
            $this->error('[Cleanup] Failed: ' . $e->getMessage());
            Log::error('[Cleanup] Failed', ['error' => $e->getMessage()]);
            return Command::FAILURE;
        }

        $total = array_sum($tally);
        $verb  = $dryRun ? 'would delete' : 'deleted';

        $this->info(sprintf('[Cleanup] Total rows %s: %d', $verb, $total));

        // Always log the totals — scheduler runs are unattended, this is
        // the only record they happened. Logged at INFO so it doesn't spam
        // alerts but is visible when investigating.
        if (! $dryRun) {
            Log::info('[Cleanup] retention prune complete', $tally);
        }

        return Command::SUCCESS;
    }

    /**
     * Execute a delete (or count it in dry-run) and emit a status line.
     *
     * @param  array<int, mixed>  $bindings
     */
    private function prune(string $deleteSql, array $bindings, bool $dryRun, string $table, string $dateColumn): int
    {
        if ($dryRun) {
            // Reconstruct a SELECT COUNT from the DELETE — sloppy but safe;
            // the deleteSql shapes are all simple WHERE clauses we control.
            $countSql = preg_replace('/^DELETE FROM /', 'SELECT COUNT(*) FROM ', $deleteSql, 1) ?? '';
            $n = (int) DB::scalar($countSql, $bindings);
        } else {
            $n = DB::affectingStatement($deleteSql, $bindings);
        }

        $this->line("{$table}: " . ($dryRun ? "would delete " : "deleted ") . $n);
        return $n;
    }

    private function utcDateDaysAgo(int $days): string
    {
        return now()->utc()->subDays($days)->toDateString();
    }
}
