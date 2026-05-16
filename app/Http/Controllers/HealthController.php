<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/*
|--------------------------------------------------------------------------
| Health endpoint
|--------------------------------------------------------------------------
|
| Returns 200 when the application and its dependencies (DB) are reachable.
| Returns 503 if any dependency check fails.
|
| Two endpoints serve different monitoring needs:
|
|   /up      — bound by Laravel's withRouting(health: '/up') in bootstrap/app.php.
|              Just confirms PHP-FPM is alive and the framework boots. Zero
|              dependency checks. Use this for load-balancer liveness probes
|              where a slow DB shouldn't cause restart cascades.
|
|   /api/health — this controller. Checks DB connectivity by running a trivial
|                 SELECT. Use this for monitoring dashboards / uptime services
|                 where you want to know if the app can actually serve requests.
|
| Both endpoints return JSON (Node-backend parity: `{ok: true, service: ...}`).
*/
class HealthController
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'db' => $this->checkDatabase(),
        ];

        $ok = collect($checks)->every(fn ($v) => $v['ok'] === true);

        return response()->json([
            'ok'      => $ok,
            'service' => 'verdict-api',
            'checks'  => $checks,
        ], $ok ? 200 : 503);
    }

    /**
     * Lightweight DB ping. SELECT 1 round-trips the connection without
     * scanning any table.
     *
     * @return array{ok: bool, error?: string}
     */
    private function checkDatabase(): array
    {
        try {
            DB::connection()->select('SELECT 1');
            return ['ok' => true];
        } catch (Throwable $e) {
            // Log the real error but DON'T leak it in the response — could
            // reveal DB path, schema, etc. Monitoring systems just need to
            // know "DB is down", not why.
            Log::error('[Health] DB ping failed', [
                'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'error' => 'db_unreachable'];
        }
    }
}
