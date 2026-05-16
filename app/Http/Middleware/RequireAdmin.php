<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin auth with two-tier brute-force lockout.
 *
 * Port of the Node adminRouter.ts requireAdmin middleware (Batch 1 part 2,
 * C8 fix). Solves the original "shared NAT locks out everyone" problem.
 *
 * Two-tier strategy:
 *
 *   Tier 1 — per (ip, secret-prefix):
 *     A genuine admin typo keeps the same wrong secret. 5 attempts on the
 *     same prefix triggers a 15-min lockout JUST for that prefix from that IP.
 *
 *   Tier 2 — per ip (across all prefixes):
 *     An attacker iterating distinct secrets defeats tier 1 (each attempt
 *     has a new prefix, fresh counter). The per-IP counter catches them:
 *     20 unique failures from one IP triggers a 60-min lockout regardless
 *     of what prefix they try next.
 *
 * Trusted IPs (config: TRUSTED_IPS env) bypass lockout entirely — operator
 * safety net so we can't lock ourselves out during incident response.
 *
 * Lockout records are persisted in the `settings` table under reserved key
 * prefixes (`admin_lockout_ip:*`, `admin_lockout_ip_prefix:*:*`) so they
 * survive process restarts. Otherwise restarting the server would reset
 * the attack budget.
 */
final class RequireAdmin
{
    private const TIER1_MAX_ATTEMPTS = 5;
    private const TIER1_LOCKOUT_MS   = 15 * 60 * 1000;
    private const TIER2_MAX_ATTEMPTS = 20;
    private const TIER2_LOCKOUT_MS   = 60 * 60 * 1000;

    public function handle(Request $request, Closure $next): Response
    {
        $ip = $this->clientIp($request);

        // Trusted-IP escape hatch — checked first so a misconfigured lockout
        // record can never lock out the operator.
        if ($this->isTrustedIp($ip)) {
            $submitted = (string) $request->header('X-Admin-Secret', '');
            if (! $this->safeEqual($submitted, (string) config('verdict.admin_secret', ''))) {
                return response()->json(['error' => 'forbidden', 'message' => 'Invalid admin secret.'], 403);
            }
            return $next($request);
        }

        $submitted   = (string) $request->header('X-Admin-Secret', '');
        $prefixHash  = $this->secretPrefixHash($submitted);
        $ipKey       = "admin_lockout_ip:{$ip}";
        $prefixKey   = "admin_lockout_ip_prefix:{$ip}:{$prefixHash}";

        // Tier 2 (per-IP global) check.
        $ipRecord = $this->getLockout($ipKey);
        if ($ipRecord['lockedUntil'] !== null) {
            if ($this->nowMs() < $ipRecord['lockedUntil']) {
                return $this->lockoutResponse($ipRecord['lockedUntil'], 'from this IP');
            }
            $this->clearLockout($ipKey);
            $ipRecord = ['count' => 0, 'lockedUntil' => null];
        }

        // Tier 1 (per-IP-+-secret-prefix) check.
        $prefixRecord = $this->getLockout($prefixKey);
        if ($prefixRecord['lockedUntil'] !== null) {
            if ($this->nowMs() < $prefixRecord['lockedUntil']) {
                return $this->lockoutResponse($prefixRecord['lockedUntil']);
            }
            $this->clearLockout($prefixKey);
            $prefixRecord = ['count' => 0, 'lockedUntil' => null];
        }

        // Secret verification.
        if (! $this->safeEqual($submitted, (string) config('verdict.admin_secret', ''))) {
            $prefixRecord['count']++;
            $ipRecord['count']++;

            // Tier 1 trip.
            if ($prefixRecord['count'] >= self::TIER1_MAX_ATTEMPTS) {
                $prefixRecord['lockedUntil'] = $this->nowMs() + self::TIER1_LOCKOUT_MS;
            }
            $this->setLockout($prefixKey, $prefixRecord);

            // Tier 2 trip.
            if ($ipRecord['count'] >= self::TIER2_MAX_ATTEMPTS) {
                $ipRecord['lockedUntil'] = $this->nowMs() + self::TIER2_LOCKOUT_MS;
                Log::warning('[Admin] IP locked out (tier-2)', [
                    'ip'       => $ip,
                    'attempts' => $ipRecord['count'],
                ]);
            }
            $this->setLockout($ipKey, $ipRecord);

            if ($prefixRecord['lockedUntil'] !== null || $ipRecord['lockedUntil'] !== null) {
                return response()->json([
                    'error'   => 'locked_out',
                    'message' => 'Too many failed attempts. Try again later.',
                ], 429);
            }

            $left = self::TIER1_MAX_ATTEMPTS - $prefixRecord['count'];
            return response()->json([
                'error'   => 'forbidden',
                'message' => "Invalid admin secret. {$left} attempt" . ($left !== 1 ? 's' : '') . ' remaining before lockout.',
            ], 403);
        }

        // Success — clear both counters for this IP.
        $this->clearLockout($prefixKey);
        $this->clearLockout($ipKey);

        return $next($request);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────────────

    private function clientIp(Request $request): string
    {
        // Prefer X-Real-IP (nginx-set) then fall back to req->ip() which
        // resolves to X-Forwarded-For client via trustProxies in bootstrap.
        return (string) ($request->header('x-real-ip') ?: $request->ip() ?: 'unknown');
    }

    private function isTrustedIp(string $ip): bool
    {
        return in_array($ip, (array) config('verdict.trusted_ips', []), true);
    }

    /**
     * First 8 hex chars of sha256(submitted) — the lockout bucket. Never
     * store or log the actual submitted secret. Collisions across distinct
     * secrets at 32 bits are negligible at admin-attempt volumes.
     */
    private function secretPrefixHash(string $submitted): string
    {
        return substr(hash('sha256', $submitted), 0, 8);
    }

    /** @return array{count: int, lockedUntil: int|null} */
    private function getLockout(string $key): array
    {
        $row = DB::table('settings')->where('key', $key)->value('value');
        if ($row === null) {
            return ['count' => 0, 'lockedUntil' => null];
        }
        $parsed = json_decode($row, true);
        if (! is_array($parsed)) {
            return ['count' => 0, 'lockedUntil' => null];
        }
        return [
            'count'       => (int) ($parsed['count'] ?? 0),
            'lockedUntil' => isset($parsed['lockedUntil']) ? (int) $parsed['lockedUntil'] : null,
        ];
    }

    /** @param array{count: int, lockedUntil: int|null} $record */
    private function setLockout(string $key, array $record): void
    {
        DB::statement(
            'INSERT INTO settings (key, value) VALUES (?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value',
            [$key, json_encode($record)]
        );
    }

    private function clearLockout(string $key): void
    {
        DB::table('settings')->where('key', $key)->delete();
    }

    private function lockoutResponse(int $lockedUntilMs, string $scopeSuffix = ''): Response
    {
        $remainingMinutes = (int) ceil(($lockedUntilMs - $this->nowMs()) / 60000);
        $minutesWord      = $remainingMinutes !== 1 ? 'minutes' : 'minute';
        $where            = $scopeSuffix !== '' ? " {$scopeSuffix}" : '';
        return response()->json([
            'error'   => 'locked_out',
            'message' => "Too many failed attempts{$where}. Try again in {$remainingMinutes} {$minutesWord}.",
        ], 429);
    }

    private function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }

    private function safeEqual(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }
        if (strlen($a) !== strlen($b)) {
            hash_equals(str_repeat('x', 32), str_repeat('y', 32));
            return false;
        }
        return hash_equals($a, $b);
    }
}
