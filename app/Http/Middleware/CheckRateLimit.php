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
 * Per-device daily rate limit for analyze and profile endpoints.
 *
 * Port of Node middleware/rateLimit.ts. Use:
 *
 *   Route::post('/api/analyze/job')->middleware('check.rate:analyze');
 *   Route::post('/api/profile/parse')->middleware('check.rate:profile');
 *
 * The type ('analyze' | 'profile') is passed as a route parameter. Score is
 * NOT handled here — score has volume-based semantics (consume jobs.length,
 * not 1) and goes through CheckScoreVolumeLimit instead.
 *
 * Behaviour summary:
 *   - subscriptions disabled globally → no limit check, still increments
 *     for analytics
 *   - Pro tier (limit=null) → no limit check, still increments
 *   - Free / trial tier → atomic check + increment; 429 with limit-exceeded
 *     details on failure
 *
 * The "atomic check + increment" is critical. Without it, two concurrent
 * requests on a free user's last allowed call can both observe used=limit-1
 * and both pass — letting the user exceed quota by one. The transaction
 * serialises the two against each other so only one passes.
 */
final class CheckRateLimit
{
    /**
     * @param Request $request
     * @param Closure $next
     * @param 'analyze'|'profile' $type — passed via 'check.rate:analyze' route alias
     */
    public function handle(Request $request, Closure $next, string $type): Response
    {
        if (! in_array($type, ['analyze', 'profile'], true)) {
            throw new \InvalidArgumentException("CheckRateLimit: invalid type '{$type}'");
        }

        /** @var Device $device — set by RequireDevice upstream */
        $device = $request->attributes->get('device');
        if ($device === null) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        // Subscriptions disabled globally → everyone is effectively Pro.
        // Still increment for analytics; skip the limit check entirely.
        if (! Setting::subscriptionsEnabled()) {
            $this->incrementUsage($device->id, $type, 1);
            return $next($request);
        }

        $tier  = $device->effectiveTier();
        $limit = Limits::CALL_LIMITS[$type][$tier] ?? null;

        // Pro tier (limit=null) — unlimited, still increments for analytics.
        if ($limit === null) {
            $this->incrementUsage($device->id, $type, 1);
            return $next($request);
        }

        $result = $this->tryConsumeUsage($device->id, $type, $limit, 1);

        if (! $result['allowed']) {
            return response()->json([
                'error'         => $tier === 'trial' ? 'trial_daily_limit' : 'rate_limit_exceeded',
                'message'       => $this->buildMessage($type, $result['limit'], $tier),
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
     * Atomically check the per-tier daily cap and increment if within limit.
     *
     * SQLite serializes writes globally, so wrapping the SELECT + UPDATE in
     * DB::transaction() makes the whole sequence appear instantaneous to
     * other writers. Without this, the read-then-write race lets two
     * concurrent requests both pass when used == limit - 1.
     *
     * @return array{allowed: bool, used: int, limit: int|null}
     */
    private function tryConsumeUsage(string $deviceId, string $type, ?int $limit, int $by): array
    {
        return DB::transaction(function () use ($deviceId, $type, $limit, $by): array {
            $today = now()->toDateString();
            $col   = $this->usageColumn($type);

            // Fetch current count. firstOrCreate via raw query is faster than
            // the Eloquent equivalent at this hot path.
            $used = (int) (DB::table('usage')
                ->where('device_id', $deviceId)
                ->where('date', $today)
                ->value($col) ?? 0);

            if ($limit !== null && $used + $by > $limit) {
                return ['allowed' => false, 'used' => $used, 'limit' => $limit];
            }

            $this->incrementUsage($deviceId, $type, $by);
            return ['allowed' => true, 'used' => $used + $by, 'limit' => $limit];
        });
    }

    /**
     * Increment a usage counter for today via atomic UPSERT.
     *
     * Single statement: INSERT for the first call of the day, UPDATE for
     * subsequent ones. ON CONFLICT(device_id, date) uses the composite PK
     * defined in the migration to detect the existing row.
     */
    private function incrementUsage(string $deviceId, string $type, int $by): void
    {
        $col = $this->usageColumn($type);
        DB::statement(
            "INSERT INTO usage (device_id, date, {$col}) VALUES (?, date('now'), ?)
             ON CONFLICT(device_id, date) DO UPDATE SET {$col} = {$col} + excluded.{$col}",
            [$deviceId, $by]
        );
    }

    /**
     * Map usage type to its DB column name. The column name is interpolated
     * into the SQL above, so this lookup MUST never accept untrusted input.
     * The route alias `check.rate:analyze` is the only entrypoint; the
     * handle() method validates the value before any SQL runs.
     */
    private function usageColumn(string $type): string
    {
        return match ($type) {
            'analyze' => 'analyze_calls',
            'profile' => 'profile_calls',
            'score'   => 'jobs_scored',
        };
    }

    private function buildMessage(string $type, int $limit, string $tier): string
    {
        $suffix = $tier === 'trial'
            ? ' Resets at midnight UTC.'
            : ' Upgrade to Pro for unlimited access.';

        if ($type === 'analyze') {
            return "Daily analysis limit ({$limit}) reached.{$suffix}";
        }
        return "Daily profile parse limit ({$limit}) reached.{$suffix}";
    }

    private function nextMidnightUtc(): string
    {
        return now()->utc()->addDay()->startOfDay()->toIso8601String();
    }
}
