<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Device;
use App\Models\Setting;
use App\Support\Limits;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-device daily score-volume cap.
 *
 * Port of the Node middleware's checkScoreVolumeLimit (from Batch 1, C1 fix).
 *
 * /api/score/batch consumes jobs.length from the daily score quota — unlike
 * analyze/profile which consume 1 per call. Separate middleware because the
 * semantics are body-driven, not call-count-driven; folding it into
 * CheckRateLimit would force a body-reading branch into the simple +1 path
 * that nothing else needs.
 *
 * Defers shape validation (jobs missing / not array / >MAX_JOBS_PER_BATCH)
 * to the controller so invalid requests return 400 without burning quota.
 *
 * Pro tier (limit=null) still passes through tryConsumeUsage so the analytics
 * counter increments — mirrors CheckRateLimit's behaviour when subscriptions
 * are disabled and everyone is effectively Pro.
 */
final class CheckScoreVolumeLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Device $device — set by RequireDevice upstream */
        $device = $request->attributes->get('device');
        if ($device === null) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $jobs = $request->input('jobs');

        // Shape validation — let the controller's validator return 400.
        // Don't consume quota for malformed or oversized batches.
        if (! is_array($jobs) || count($jobs) === 0 || count($jobs) > Limits::MAX_JOBS_PER_BATCH) {
            return $next($request);
        }

        $count = count($jobs);

        // Subscriptions disabled globally → still increment for analytics.
        if (! Setting::subscriptionsEnabled()) {
            $this->incrementUsage($device->id, $count);
            return $next($request);
        }

        $tier  = $device->effectiveTier();
        $limit = Limits::SCORE_LIMITS[$tier] ?? null;

        // Pro tier (limit=null) — unlimited, still increments for analytics.
        if ($limit === null) {
            $this->incrementUsage($device->id, $count);
            return $next($request);
        }

        $result = $this->tryConsumeUsage($device->id, $limit, $count);

        if (! $result['allowed']) {
            return response()->json([
                'error'         => $tier === 'trial' ? 'trial_daily_limit' : 'rate_limit_exceeded',
                'message'       => $this->buildMessage($result['limit'], $tier),
                'limit'         => $result['limit'],
                'used'          => $result['used'],
                'tier'          => $tier,
                'needs_upgrade' => $tier === 'free',
                'reset_at'      => $this->nextMidnightUtc(),
            ], 429);
        }

        return $next($request);
    }

    /**
     * @return array{allowed: bool, used: int, limit: int}
     */
    private function tryConsumeUsage(string $deviceId, int $limit, int $by): array
    {
        return DB::transaction(function () use ($deviceId, $limit, $by): array {
            $today = now()->toDateString();
            $used  = (int) (DB::table('usage')
                ->where('device_id', $deviceId)
                ->where('date', $today)
                ->value('jobs_scored') ?? 0);

            if ($used + $by > $limit) {
                return ['allowed' => false, 'used' => $used, 'limit' => $limit];
            }

            $this->incrementUsage($deviceId, $by);
            return ['allowed' => true, 'used' => $used + $by, 'limit' => $limit];
        });
    }

    private function incrementUsage(string $deviceId, int $by): void
    {
        DB::statement(
            "INSERT INTO usage (device_id, date, jobs_scored) VALUES (?, date('now'), ?)
             ON CONFLICT(device_id, date) DO UPDATE SET jobs_scored = jobs_scored + excluded.jobs_scored",
            [$deviceId, $by]
        );
    }

    private function buildMessage(int $limit, string $tier): string
    {
        $suffix = $tier === 'trial'
            ? ' Resets at midnight UTC.'
            : ' Upgrade to Pro for unlimited scoring.';
        return "Daily scoring limit ({$limit} jobs) reached.{$suffix}";
    }

    private function nextMidnightUtc(): string
    {
        return now()->utc()->addDay()->startOfDay()->toIso8601String();
    }
}
