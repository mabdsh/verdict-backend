<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\EmailHelper;
use App\Support\Limits;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hourly grace-period enforcement.
 *
 * Finds devices that have been in past_due status for longer than
 * PAST_DUE_GRACE_DAYS (7) and downgrades them to free/expired.
 *
 * Why this exists:
 *   When LemonSqueezy reports payment_failed, our webhook stamps
 *   past_due_at and keeps the device at tier='pro' with status='past_due'.
 *   Device::effectiveTier() then checks `past_due_at + grace_days < now()`
 *   to decide whether to still grant Pro access. This is correct for ANY
 *   read path that goes through effectiveTier(), but it doesn't change
 *   the STORED state — the row keeps saying tier='pro', status='past_due'
 *   until something updates it.
 *
 *   Two things eventually update it:
 *     - LemonSqueezy fires subscription_expired (when their dunning runs
 *       out, ~16 days). Our webhook downgrades the row.
 *     - This command (every hour, after 7 days). We downgrade ourselves.
 *
 *   This command exists to make the stored state match the effective
 *   state. Without it, admin queries like "list active subscribers"
 *   would over-count by including past_due devices that are effectively
 *   already free.
 *
 * Idempotent:
 *   Once a row is downgraded to status='expired', it no longer matches the
 *   WHERE filter (status = 'past_due'), so subsequent runs skip it. Safe
 *   to run multiple times per hour if the scheduler misfires.
 *
 * Usage:
 *   php artisan verdict:expire-subscriptions
 *   php artisan verdict:expire-subscriptions --dry-run
 */
final class ExpireStaleSubscriptionsCommand extends Command
{
    protected $signature = 'verdict:expire-subscriptions
                            {--dry-run : List devices that would be downgraded without changing them}';

    protected $description = 'Downgrade past_due devices whose grace period has expired';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $graceDays = Limits::PAST_DUE_GRACE_DAYS;

        // SQLite datetime arithmetic: stamp + grace_days. Anything older
        // than that, where the status is still past_due, has used up grace.
        $cutoffExpr = "datetime(past_due_at, '+{$graceDays} days')";

        $candidates = DB::select(
            "SELECT id, email, subscription_id, past_due_at
             FROM devices
             WHERE subscription_status = 'past_due'
               AND past_due_at IS NOT NULL
               AND {$cutoffExpr} < datetime('now')"
        );

        $count = count($candidates);
        $this->info(($dryRun ? '[DRY RUN] Would downgrade ' : 'Downgrading ') . "{$count} stale past_due subscription(s)");

        foreach ($candidates as $row) {
            $masked = EmailHelper::mask($row->email ?? '');
            $prefix = substr($row->id, 0, 8);
            $this->line("  {$prefix}…  email={$masked}  past_due_at={$row->past_due_at}");
        }

        if ($dryRun || $count === 0) {
            return Command::SUCCESS;
        }

        try {
            $affected = DB::affectingStatement(
                "UPDATE devices SET
                    tier                 = 'free',
                    subscription_status  = 'expired',
                    subscription_ends_at = NULL,
                    past_due_at          = NULL,
                    last_seen            = datetime('now')
                 WHERE subscription_status = 'past_due'
                   AND past_due_at IS NOT NULL
                   AND {$cutoffExpr} < datetime('now')"
            );

            $this->info("Downgraded {$affected} device(s)");

            // Audit log for the operator — admin dashboard's request_logs
            // shows API calls, not Artisan runs, so log here.
            Log::warning('[Expire] downgraded stale past_due devices', [
                'count'      => $affected,
                'grace_days' => $graceDays,
            ]);
        } catch (Throwable $e) {
            $this->error('[Expire] Failed: ' . $e->getMessage());
            Log::error('[Expire] Failed', ['error' => $e->getMessage()]);
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
