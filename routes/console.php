<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes + Schedule
|--------------------------------------------------------------------------
|
| Replaces the Node backend's setInterval-based maintenance jobs.
|
| To run the scheduler, add this to crontab on the production server:
|
|   * * * * * cd /var/www/verdict-api && php artisan schedule:run >> /dev/null 2>&1
|
| The single cron line above lets Laravel run multiple internal schedules
| at arbitrary frequencies. Laravel checks every minute which jobs are due
| and dispatches them. Idle minutes cost ~1ms and zero queries.
|
| Verification:
|   php artisan schedule:list     — shows everything that's scheduled
|   php artisan schedule:work     — runs the scheduler in the foreground
|                                    (for local testing without cron)
*/

// ── Demo command (kept from Phase 1, harmless) ────────────────────────────────
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── Daily retention prune ─────────────────────────────────────────────────────
// Runs once a day at 03:15 UTC — low-traffic hour globally. The prune is
// cheap (indexed DELETEs) but locks the SQLite file briefly per table.
//
// withoutOverlapping(): if a previous run somehow hasn't finished after
// 24h (it should never take more than a few seconds), skip rather than
// queueing a second copy.
//
// runInBackground(): the cron-dispatcher process doesn't wait for the
// command to finish. Lets other scheduled jobs run on time if cleanup
// drags.
Schedule::command('verdict:cleanup-logs')
    ->dailyAt('03:15')
    ->withoutOverlapping()
    ->runInBackground();

// ── Hourly grace-period enforcement ───────────────────────────────────────────
// Runs at :07 past every hour — staggered off the top of the hour to avoid
// colliding with any other minute-0 tasks.
//
// onOneServer(): when this app moves to multi-instance, only one server
// runs this per hour. Currently a no-op on single-instance but cheap to
// configure correctly upfront.
Schedule::command('verdict:expire-subscriptions')
    ->hourlyAt(7)
    ->withoutOverlapping()
    ->onOneServer();
