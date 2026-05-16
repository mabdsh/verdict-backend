<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Setting;
use App\Support\Limits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Operator admin dashboard.
 *
 * Port of the Node backend's adminRouter.ts. All methods behind the
 * require.admin middleware (two-tier brute-force lockout, trusted-IP
 * bypass — see Phase 4).
 *
 * Response shapes are byte-identical to the Node version so the existing
 * static admin panel (public/admin-panel/*.html + .js) works unchanged
 * against this controller.
 *
 * Eleven methods organised by area:
 *
 *   ── Top-level metrics ──
 *   stats()              — devices, calls today/week, performance summary
 *   daily()              — usage time-series
 *   latency()            — request latency time-series
 *   usage()              — top devices by usage
 *
 *   ── Request logs ──
 *   logs()               — list, filterable by status/endpoint
 *   clearLogs()          — DELETE entire request_logs table
 *
 *   ── Subscriptions ──
 *   subscriptionStats()       — tier counts, MRR estimate
 *   toggleSubscriptions()     — paywall on/off switch
 *   subscriptionDevices()     — list/search active subscription rows
 *
 *   ── Per-device overrides ──
 *   grantProOverride(id)      — set tier_override = 'pro'
 *   revokeProOverride(id)     — clear tier_override
 *
 * Most read methods use raw SQL via DB::select() rather than Eloquent.
 * The aggregations involve SQLite date functions and multi-column SUMs
 * that are cleaner as raw queries than as Eloquent expression trees.
 */
final class AdminController
{
    // ────────────────────────────────────────────────────────────────────────
    // Top-level metrics
    // ────────────────────────────────────────────────────────────────────────

    /**
     * GET /admin/stats — top-line dashboard metrics.
     */
    public function stats(): JsonResponse
    {
        $totalDevices = Device::count();

        $newToday = (int) DB::scalar(
            "SELECT COUNT(*) FROM devices WHERE date(first_seen) = date('now')"
        );

        $activeToday = (int) DB::scalar(
            "SELECT COUNT(DISTINCT device_id) FROM usage WHERE date = date('now')"
        );

        $callsToday = DB::selectOne(
            "SELECT COALESCE(SUM(jobs_scored),0)   as scored,
                    COALESCE(SUM(analyze_calls),0) as analyze,
                    COALESCE(SUM(profile_calls),0) as profile
             FROM usage WHERE date = date('now')"
        );

        $callsWeek = DB::selectOne(
            "SELECT COALESCE(SUM(jobs_scored),0)   as scored,
                    COALESCE(SUM(analyze_calls),0) as analyze,
                    COALESCE(SUM(profile_calls),0) as profile
             FROM usage WHERE date >= ?",
            [$this->utcDateDaysAgo(6)]
        );

        $panelOpensToday = (int) DB::scalar(
            "SELECT COUNT(*) FROM panel_opens WHERE date = date('now')"
        );

        // Trial users currently in their 7-day window AND haven't yet bought.
        // The tier_override exclusion catches admin-comped trial accounts that
        // shouldn't be in the conversion funnel.
        $trialUsersNow = (int) DB::scalar(
            "SELECT COUNT(*) FROM devices
             WHERE trial_started_at IS NOT NULL
               AND datetime(trial_started_at, '+7 days') > datetime('now')
               AND (tier != 'pro' OR tier IS NULL)
               AND (tier_override IS NULL OR tier_override != 'pro')"
        );

        $perf = DB::selectOne(
            "SELECT COALESCE(AVG(latency_ms),0) as avg_lat,
                    COALESCE(MAX(latency_ms),0) as max_lat,
                    COUNT(*)                    as total_reqs
             FROM request_logs WHERE date(created_at) = date('now') AND status = 200"
        );

        $errorsToday = (int) DB::scalar(
            "SELECT COUNT(*) FROM request_logs
             WHERE status >= 500 AND date(created_at) = date('now')"
        );

        return response()->json([
            'devices' => [
                'total'        => $totalDevices,
                'new_today'    => $newToday,
                'active_today' => $activeToday,
                'trial_now'    => $trialUsersNow,
            ],
            'calls_today' => [
                'scored'      => (int) $callsToday->scored,
                'analyze'     => (int) $callsToday->analyze,
                'profile'     => (int) $callsToday->profile,
                'panel_opens' => $panelOpensToday,
                'total'       => (int) $callsToday->scored + (int) $callsToday->analyze + (int) $callsToday->profile,
            ],
            'calls_week' => [
                'scored'  => (int) $callsWeek->scored,
                'analyze' => (int) $callsWeek->analyze,
                'profile' => (int) $callsWeek->profile,
                'total'   => (int) $callsWeek->scored + (int) $callsWeek->analyze + (int) $callsWeek->profile,
            ],
            'performance' => [
                'avg_latency_ms'            => (int) round((float) $perf->avg_lat),
                'max_latency_ms'            => (int) $perf->max_lat,
                'successful_requests_today' => (int) $perf->total_reqs,
                'errors_today'              => $errorsToday,
            ],
        ]);
    }

    /**
     * GET /admin/daily — per-day usage breakdown for chart rendering.
     *
     * Query: ?days=N  (1-90, default 7)
     */
    public function daily(Request $request): JsonResponse
    {
        $days = min(max((int) $request->query('days', 7), 1), 90);
        $cutoff = $this->utcDateDaysAgo($days - 1);

        $rows = DB::select(
            "SELECT date,
                    COALESCE(SUM(jobs_scored),0)                             as scored,
                    COALESCE(SUM(analyze_calls),0)                           as analyze,
                    COALESCE(SUM(profile_calls),0)                           as profile,
                    COALESCE(SUM(jobs_scored+analyze_calls+profile_calls),0) as total
             FROM usage
             WHERE date >= ?
             GROUP BY date
             ORDER BY date ASC",
            [$cutoff]
        );

        return response()->json([
            'days' => $days,
            'data' => $rows,
        ]);
    }

    /**
     * GET /admin/latency — per-day latency stats for chart rendering.
     *
     * Only counts successful (status 200) requests — including 4xx/5xx in
     * the average inflates the number with deliberately-fast 401s.
     *
     * Query: ?days=N  (1-90, default 7)
     */
    public function latency(Request $request): JsonResponse
    {
        $days = min(max((int) $request->query('days', 7), 1), 90);
        $cutoff = $this->utcDateDaysAgo($days - 1);

        $rows = DB::select(
            "SELECT date(created_at)        as date,
                    ROUND(AVG(latency_ms))  as avg_ms,
                    MAX(latency_ms)         as max_ms,
                    COUNT(*)                as requests
             FROM request_logs
             WHERE date(created_at) >= ? AND status = 200
             GROUP BY date(created_at)
             ORDER BY date ASC",
            [$cutoff]
        );

        return response()->json([
            'days' => $days,
            'data' => $rows,
        ]);
    }

    /**
     * GET /admin/usage — per-device usage breakdown, top N by total volume.
     *
     * Query: ?days=N  (1-90, default 7)
     *        ?limit=M (1-100, default 20)
     */
    public function usage(Request $request): JsonResponse
    {
        $days  = min(max((int) $request->query('days',  7),  1), 90);
        $limit = min(max((int) $request->query('limit', 20), 1), 100);
        $cutoff = $this->utcDateDaysAgo($days);

        $rows = DB::select(
            "SELECT d.id as device_id, d.tier, d.tier_override, d.subscription_status, d.email,
                    SUM(u.jobs_scored)   as scored,
                    SUM(u.analyze_calls) as analyze,
                    SUM(u.profile_calls) as profile,
                    SUM(u.jobs_scored+u.analyze_calls+u.profile_calls) as total,
                    MIN(u.date) as first_active, MAX(u.date) as last_active
             FROM usage u
             JOIN devices d ON d.id = u.device_id
             WHERE u.date >= ?
             GROUP BY u.device_id
             ORDER BY total DESC
             LIMIT ?",
            [$cutoff, $limit]
        );

        return response()->json([
            'days'    => $days,
            'count'   => count($rows),
            'devices' => $rows,
        ]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Request logs
    // ────────────────────────────────────────────────────────────────────────

    /**
     * GET /admin/logs — list request_logs, newest first.
     *
     * Query: ?limit=N (1-500, default 100)
     *        ?errors=true → only status >= 400
     *        ?endpoint=/api/score/batch → exact match (max 256 chars)
     */
    public function logs(Request $request): JsonResponse
    {
        $limit      = min(max((int) $request->query('limit', 100), 1), 500);
        $errorsOnly = $request->query('errors') === 'true';
        // Cap endpoint string length to prevent abuse of the WHERE index.
        $endpoint = is_string($request->query('endpoint'))
            ? substr((string) $request->query('endpoint'), 0, 256)
            : null;

        $conditions = [];
        $params     = [];

        if ($errorsOnly) {
            $conditions[] = 'status >= 400';
        }
        if ($endpoint !== null && $endpoint !== '') {
            $conditions[] = 'endpoint = ?';
            $params[]     = $endpoint;
        }

        $where = empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions);

        $params[] = $limit;
        $rows = DB::select(
            "SELECT id, device_id, endpoint, latency_ms, status, error, created_at
             FROM request_logs
             {$where}
             ORDER BY created_at DESC
             LIMIT ?",
            $params
        );

        return response()->json([
            'count' => count($rows),
            'logs'  => $rows,
        ]);
    }

    /**
     * DELETE /admin/logs — wipe the entire request_logs table.
     *
     * Use sparingly — the table is normally pruned to 30 days by the
     * Phase 10 scheduled job. Manual wipe is only needed for emergency
     * disk-space recovery.
     */
    public function clearLogs(): JsonResponse
    {
        $deletedCount = DB::table('request_logs')->delete();

        Log::warning('[Admin] cleared request_logs', [
            'deleted_count' => $deletedCount,
        ]);

        return response()->json([
            'ok'           => true,
            'deletedCount' => $deletedCount,
            'message'      => "Cleared {$deletedCount} request log " . ($deletedCount === 1 ? 'entry' : 'entries') . '.',
        ]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Subscription analytics
    // ────────────────────────────────────────────────────────────────────────

    /**
     * GET /admin/subscription/stats — tier breakdown, MRR estimate.
     *
     * MRR uses the monthly price from Limits::PRICING. Yearly subscribers
     * pay monthly_equivalent ≈ yearly_usd / 12, so this is a slight
     * overestimate for that segment — acceptable for a dashboard metric.
     */
    public function subscriptionStats(): JsonResponse
    {
        $subsEnabled    = Setting::subscriptionsEnabled();
        $pricePerMonth  = Limits::PRICING['monthly_usd'];

        $tierCountRows = DB::select(
            "SELECT tier, COUNT(*) as count FROM devices GROUP BY tier"
        );
        $tierCounts = [];
        foreach ($tierCountRows as $r) {
            $tierCounts[$r->tier ?? 'null'] = (int) $r->count;
        }

        $statusCountRows = DB::select(
            "SELECT subscription_status, COUNT(*) as count
             FROM devices WHERE subscription_status IS NOT NULL
             GROUP BY subscription_status"
        );
        $statusCounts = [];
        foreach ($statusCountRows as $r) {
            $statusCounts[$r->subscription_status] = (int) $r->count;
        }

        $overridePro = (int) DB::scalar(
            "SELECT COUNT(*) FROM devices WHERE tier_override = 'pro'"
        );

        $revenueDevices = (int) DB::scalar(
            "SELECT COUNT(*) FROM devices WHERE subscription_status = 'active'"
        );

        $trialActive = (int) DB::scalar(
            "SELECT COUNT(*) FROM devices
             WHERE trial_started_at IS NOT NULL
               AND datetime(trial_started_at, '+7 days') > datetime('now')"
        );

        return response()->json([
            'subscriptions_enabled' => $subsEnabled,
            'tier_counts'           => $tierCounts,
            'status_counts'         => $statusCounts,
            'override_pro'          => $overridePro,
            'active_subscribers'    => $revenueDevices,
            'trial_active'          => $trialActive,
            'estimated_mrr'         => round($revenueDevices * $pricePerMonth, 2),
        ]);
    }

    /**
     * POST /admin/subscription/toggle — flip the paywall on/off.
     *
     * When OFF, every user gets Pro access regardless of their stored tier
     * (Device::effectiveTier short-circuits to 'pro' when this setting
     * is false). Used during outages or promotional periods.
     */
    public function toggleSubscriptions(): JsonResponse
    {
        $next = Setting::toggleSubscriptions();

        Log::warning('[Admin] subscriptions toggled', [
            'enabled' => $next,
        ]);

        return response()->json([
            'ok'                    => true,
            'subscriptions_enabled' => $next,
            'message'               => $next
                ? 'Subscriptions enabled — free tier limits now enforced.'
                : 'Subscriptions disabled — all users get Pro limits.',
        ]);
    }

    /**
     * GET /admin/subscription/devices — list or search subscribed devices.
     *
     * Query: ?q=foo  → search by email (LIKE %foo%) OR device id prefix
     *        ?limit=N (1-50, default 20)
     *
     * No query → all devices with a subscription_id, newest activity first.
     */
    public function subscriptionDevices(Request $request): JsonResponse
    {
        $q     = trim((string) $request->query('q', ''));
        $limit = min(max((int) $request->query('limit', 20), 1), 50);

        if ($q === '') {
            $rows = DB::select(
                "SELECT id, email, tier, tier_override, subscription_id, subscription_status,
                        subscription_ends_at, first_seen, last_seen
                 FROM devices WHERE subscription_id IS NOT NULL
                 ORDER BY last_seen DESC
                 LIMIT ?",
                [$limit]
            );
        } else {
            $rows = DB::select(
                "SELECT id, email, tier, tier_override, subscription_id, subscription_status,
                        subscription_ends_at, first_seen, last_seen
                 FROM devices
                 WHERE LOWER(email) LIKE LOWER(?) OR id LIKE ?
                 ORDER BY last_seen DESC
                 LIMIT ?",
                ['%' . $q . '%', $q . '%', $limit]
            );
        }

        return response()->json([
            'count'   => count($rows),
            'devices' => $rows,
        ]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Per-device overrides
    // ────────────────────────────────────────────────────────────────────────

    /**
     * POST /admin/devices/{id}/grant-pro — set tier_override = 'pro'.
     *
     * Used to comp specific accounts (early adopters, support refunds, etc.)
     * Wins over the normal tier-resolution priority order in Device::effectiveTier.
     */
    public function grantProOverride(string $id): JsonResponse
    {
        $device = Device::find($id);
        if ($device === null) {
            return response()->json(['error' => 'device_not_found'], 404);
        }

        $device->setTierOverride('pro');

        Log::info('[Admin] granted pro override', [
            'device_id' => substr($id, 0, 8),
        ]);

        return response()->json([
            'ok'      => true,
            'message' => "Device " . substr($id, 0, 8) . "… granted Pro override.",
        ]);
    }

    /**
     * DELETE /admin/devices/{id}/grant-pro — clear tier_override.
     *
     * Device falls back to its normal tier resolution after this — if they
     * have an active subscription they're still Pro, otherwise they revert
     * to free or trial as appropriate.
     */
    public function revokeProOverride(string $id): JsonResponse
    {
        $device = Device::find($id);
        if ($device === null) {
            return response()->json(['error' => 'device_not_found'], 404);
        }

        $device->setTierOverride(null);

        Log::info('[Admin] revoked pro override', [
            'device_id' => substr($id, 0, 8),
        ]);

        return response()->json([
            'ok'      => true,
            'message' => "Pro override removed from device " . substr($id, 0, 8) . "….",
        ]);
    }

    /**
     * GET /admin/webhooks — LemonSqueezy webhook delivery health.
     *
     * Returns aggregate counts (total received, distinct event types) plus
     * the most-recent N webhooks for the operator dashboard. Useful for
     * monitoring payment flow — if subscriptions stop coming through, this
     * view shows whether LS is even talking to us.
     *
     * Query: ?limit=N (1-100, default 30)
     */
    public function webhooks(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->query('limit', 30), 1), 100);
 
        $totalAll = (int) DB::scalar('SELECT COUNT(*) FROM webhook_events');
 
        $today = (int) DB::scalar(
            "SELECT COUNT(*) FROM webhook_events WHERE date(received_at) = date('now')"
        );
 
        $thisWeek = (int) DB::scalar(
            "SELECT COUNT(*) FROM webhook_events WHERE received_at >= ?",
            [now()->utc()->subDays(6)->toDateString()]
        );
 
        // Per-event-type counts (last 30 days). Lets the operator see at a
        // glance which events dominate — usually subscription_updated is
        // the bulk.
        $byEvent = DB::select(
            "SELECT event_name, COUNT(*) as count
             FROM webhook_events
             WHERE received_at >= ?
             GROUP BY event_name
             ORDER BY count DESC",
            [now()->utc()->subDays(30)->toDateString()]
        );
 
        // Most-recent N events with provider + event_name + received_at.
        $recent = DB::select(
            "SELECT provider, event_id, event_name, received_at
             FROM webhook_events
             ORDER BY received_at DESC
             LIMIT ?",
            [$limit]
        );
 
        return response()->json([
            'totals' => [
                'all_time' => $totalAll,
                'today'    => $today,
                'week'     => $thisWeek,
            ],
            'by_event' => $byEvent,
            'recent'   => $recent,
        ]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Return YYYY-MM-DD for N days ago in UTC. Matches the Node helper.
     */
    private function utcDateDaysAgo(int $days): string
    {
        return now()->utc()->subDays($days)->toDateString();
    }
}
