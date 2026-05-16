<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Verdict — project-specific configuration
|--------------------------------------------------------------------------
|
| Every project-specific setting lives here, read from env() at boot time
| (cached by `php artisan config:cache` in production). Never call env()
| directly from application code outside config files — when configs are
| cached, env() returns null. Always go through config('verdict.xxx').
|
| Required vars are validated at boot in App\Providers\AppServiceProvider.
| Missing required values cause the application to refuse to start.
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Client authentication
    |--------------------------------------------------------------------------
    |
    | The shared secret the extension sends in X-Client-Secret. Same value as
    | the Node backend's CLIENT_SECRET env var. Copy it across at cutover.
    |
    | Limitation (Batch 1 / Batch 6): this is shipped inside the extension
    | binary and therefore world-readable. It is NOT a meaningful security
    | boundary; the rate limiting + device-ID enforcement are what actually
    | protect the API. Replaced by per-install bearer tokens in Batch 6.
    */
    'client_secret' => env('CLIENT_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Admin authentication
    |--------------------------------------------------------------------------
    |
    | Shared secret for /admin endpoints. Two-tier brute-force lockout is
    | implemented in P9 (per (ip, secret-prefix) AND per-ip global counter).
    */
    'admin_secret' => env('ADMIN_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Trusted IPs
    |--------------------------------------------------------------------------
    |
    | IPs that bypass rate limits AND admin lockout. Use your operator/CI IP
    | so you can't lock yourself out during an incident. Comma-separated;
    | parsed into an array here so consumers don't reparse.
    */
    'trusted_ips' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_IPS', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Allowed extension IDs (CORS)
    |--------------------------------------------------------------------------
    |
    | Production: every chrome-extension:// origin must match an ID in this
    | list. AppServiceProvider refuses to boot if this is empty in
    | production environments.
    |
    | Non-production: list is ignored; any chrome-extension://* origin and
    | http://localhost is allowed (so local unpacked-extension development
    | works without needing the ID).
    */
    'allowed_extension_ids' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ALLOWED_EXTENSION_IDS', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Groq API
    |--------------------------------------------------------------------------
    |
    | Used by App\Services\GroqService (Phase 5). Two model tiers: SMART for
    | scoring/analysis, FAST for profile parsing (smaller / cheaper).
    */
    'groq' => [
        'api_key'    => env('GROQ_API_KEY'),
        'model_smart' => 'llama-3.3-70b-versatile',
        'model_fast'  => 'llama-3.1-8b-instant',
        'base_url'    => 'https://api.groq.com/openai/v1',
        // Request timeout in seconds. Groq is fast but the SMART model can
        // take 10–15s on a 50-job batch.
        'timeout'     => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | LemonSqueezy
    |--------------------------------------------------------------------------
    */
    'lemonsqueezy' => [
        'signing_secret'    => env('LEMONSQUEEZY_SIGNING_SECRET'),
        'store_subdomain'   => env('LEMONSQUEEZY_STORE_SUBDOMAIN'),
        'variant_monthly'   => env('LEMONSQUEEZY_VARIANT_MONTHLY'),
        'variant_yearly'    => env('LEMONSQUEEZY_VARIANT_YEARLY'),
    ],

];
