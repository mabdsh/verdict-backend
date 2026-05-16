<?php

declare(strict_types=1);

use App\Http\Middleware\CheckRateLimit;
use App\Http\Middleware\CheckScoreVolumeLimit;
use App\Http\Middleware\LogRequest;
use App\Http\Middleware\RequireAdmin;
use App\Http\Middleware\RequireDevice;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Application bootstrap (Laravel 12)
|--------------------------------------------------------------------------
|
| Updated for Phase 9: admin route group activated.
*/

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // ── API routes (/api/*) ────────────────────────────────────────
            Route::middleware(['api', 'throttle:globalEdge', 'log.request'])
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            // ── Webhook routes (/webhook/*) ────────────────────────────────
            // No api group, no rate limit, no log.request. Signature is auth.
            Route::prefix('webhook')
                ->group(base_path('routes/webhook.php'));

            // ── Admin routes (/admin/*) ────────────────────────────────────
            // require.admin handles two-tier brute-force lockout. log.request
            // captures every admin action for audit via /admin/logs itself.
            // No api throttle:globalEdge here — admin endpoints don't need
            // the per-IP cap, and trusted operator IPs are already exempt
            // from the admin lockout layer.
            Route::middleware(['require.admin', 'log.request'])
                ->prefix('admin')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // ── Middleware aliases ─────────────────────────────────────────────
        $middleware->alias([
            'require.device'      => RequireDevice::class,
            'require.admin'       => RequireAdmin::class,
            'check.rate'          => CheckRateLimit::class,
            'check.score.volume' => CheckScoreVolumeLimit::class,
            'log.request'         => LogRequest::class,
        ]);

        // ── Trust proxy headers ────────────────────────────────────────────
        $middleware->trustProxies(at: '*', headers:
            Request::HEADER_X_FORWARDED_FOR |
            Request::HEADER_X_FORWARDED_HOST |
            Request::HEADER_X_FORWARDED_PORT |
            Request::HEADER_X_FORWARDED_PROTO
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // ── Consistent JSON error shape ────────────────────────────────────
        $exceptions->render(function (\Throwable $e, Request $request) {
            // Apply consistent JSON error shape to /api/*, /webhook/*, /admin/*.
            if (! $request->is('api/*') && ! $request->is('webhook/*') && ! $request->is('admin/*')) {
                return null;
            }

            $status = method_exists($e, 'getStatusCode')
                ? $e->getStatusCode()
                : 500;

            $message = env('APP_ENV') === 'production'
                ? 'An unexpected error occurred. Please try again.'
                : $e->getMessage();

            return response()->json([
                'error'   => 'internal_error',
                'message' => $message,
            ], $status >= 400 && $status < 600 ? $status : 500);
        });
    })->create();
