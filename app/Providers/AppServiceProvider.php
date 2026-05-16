<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Device;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // No bindings yet. Services (GroqService, EmailService) get registered
        // in later phases via constructor injection — no explicit binding
        // needed because Laravel's container resolves them automatically.
    }

    /**
     * Bootstrap any application services.
     *
     * Runs after register() on every request (in PHP-FPM) or once per
     * Octane worker. Anything expensive should be lazy-loaded.
     */
    public function boot(): void
    {
        $this->validateRequiredConfig();
        $this->registerRateLimiters();
        $this->registerRequestMacros();
    }

    /**
     * Fail loudly at boot if required configuration is missing.
     *
     * Port of the Node backend's startup env validation (src/index.ts).
     * A missing required value causes the application to refuse to start —
     * better than silent mid-request failures.
     */
    private function validateRequiredConfig(): void
    {
        // Skip during console commands — migrations, key:generate, etc.
        // should work on a fresh checkout without all secrets populated.
        if ($this->app->runningInConsole()) {
            return;
        }

        $required = [
            'verdict.client_secret'                => 'CLIENT_SECRET',
            'verdict.admin_secret'                 => 'ADMIN_SECRET',
            // 'verdict.lemonsqueezy.signing_secret'  => 'LEMONSQUEEZY_SIGNING_SECRET',
            'verdict.groq.api_key'                 => 'GROQ_API_KEY',
        ];

        $missing = [];
        foreach ($required as $configKey => $envVar) {
            if (empty(config($configKey))) {
                $missing[] = $envVar;
            }
        }

        if ($this->app->environment('production')) {
            $allowedIds = config('verdict.allowed_extension_ids', []);
            if (empty($allowedIds)) {
                $missing[] = 'ALLOWED_EXTENSION_IDS (required in production)';
            }
        }

        if (! empty($missing)) {
            Log::critical('[Startup] Missing required environment variables', [
                'missing' => $missing,
            ]);
            throw new \RuntimeException(
                'Missing required environment variables: ' . implode(', ', $missing)
                . '. Copy .env.example to .env and fill in the values.'
            );
        }
    }

    /**
     * Register named edge rate limiters.
     *
     * Port of the Node middleware/edgeRateLimit.ts factories (Batch 1 part 2).
     * Five named limiters attached to routes via:
     *
     *   ->middleware('throttle:scoreEdge')
     *   ->middleware('throttle:aiEdge')
     *   ->middleware('throttle:credentialEdge')
     *   ->middleware('throttle:panelEdge')
     *   ->middleware('throttle:globalEdge')
     *
     * Routes that need MULTIPLE limiters just chain them:
     *
     *   ->middleware(['throttle:scoreEdge', 'throttle:globalEdge'])
     *
     * Trusted IPs (TRUSTED_IPS env) bypass via Limit::none(). Same env var
     * as the admin-lockout trusted list — operator manages one allowlist.
     */
    private function registerRateLimiters(): void
    {
        // /api/score/batch — the single most expensive endpoint (Groq + 50-job
        // batch). 120/min/IP is ~2× normal Pro user pace.
        RateLimiter::for('scoreEdge', fn (Request $r) =>
            $this->limitOrNone($r, Limit::perMinute(120))
        );

        // /api/analyze/job, /api/profile/parse — bounded above the daily
        // per-device limit so legitimate users never see this.
        RateLimiter::for('aiEdge', fn (Request $r) =>
            $this->limitOrNone($r, Limit::perMinute(30))
        );

        // /api/trial/activate, /api/subscription/restore/*  — credential-
        // touching endpoints. Conservative caps to slow enumeration:
        // 8 per 15min/IP. The per-email anti-spam cap in restore/start
        // (max 3 pending codes) handles the email-bomb vector.
        RateLimiter::for('credentialEdge', fn (Request $r) =>
            $this->limitOrNone($r, Limit::perMinutes(15, 8))
        );

        // /api/panel/open — cheap DB-only endpoint but called frequently.
        // 240/min allows 4/sec sustained which is above human click rate.
        RateLimiter::for('panelEdge', fn (Request $r) =>
            $this->limitOrNone($r, Limit::perMinute(240))
        );

        // Global catch-all. Attached to every API route via the api
        // middleware group so we always have a floor.
        RateLimiter::for('globalEdge', fn (Request $r) =>
            $this->limitOrNone($r, Limit::perMinute(600))
        );
    }

    /**
     * Return Limit::none() for trusted IPs, otherwise the provided limit
     * keyed by the request IP.
     */
    private function limitOrNone(Request $request, Limit $limit): Limit
    {
        $ip = (string) ($request->ip() ?? 'unknown');
        if (in_array($ip, (array) config('verdict.trusted_ips', []), true)) {
            return Limit::none();
        }
        return $limit->by($ip);
    }

    /**
     * Register custom request macros.
     *
     * $request->device() returns the resolved Device model that RequireDevice
     * attached to $request->attributes. Cleaner than the verbose
     * $request->attributes->get('device') at every controller call site.
     *
     * Returns NULL if no device is attached (i.e. the route doesn't use
     * the require.device middleware). Controllers under require.device
     * can rely on this being non-null and may use the ->deviceOrFail()
     * macro for an explicit assertion.
     */
    private function registerRequestMacros(): void
    {
        Request::macro('device', function (): ?Device {
            /** @var Request $this */
            return $this->attributes->get('device');
        });

        Request::macro('deviceOrFail', function (): Device {
            /** @var Request $this */
            $device = $this->attributes->get('device');
            if (! $device instanceof Device) {
                throw new \RuntimeException(
                    'No device attached to request — is the require.device middleware applied?'
                );
            }
            return $device;
        });

        Request::macro('requestId', function (): ?string {
            /** @var Request $this */
            return $this->attributes->get('request_id');
        });
    }
}
