<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| CORS Configuration
|--------------------------------------------------------------------------
|
| Port of the Node backend's CORS middleware (server.ts):
|
|   Production:  every chrome-extension:// origin must match an allowlisted ID.
|                Any other origin is rejected.
|
|   Development: any chrome-extension://* and http://localhost origin allowed
|                so unpacked extensions work without their ID being known.
|
| Notes on Laravel CORS:
|   - `paths` defines WHICH routes the CORS middleware actually runs on.
|     We list api/*, webhook/* (when it lands in P8), admin/*.
|   - `allowed_origins_patterns` accepts regex; we use it instead of
|     `allowed_origins` so production and dev both fit one rule set.
|   - Browsers send a preflight OPTIONS request for non-simple CORS requests;
|     Laravel's HandleCors middleware handles those automatically as long as
|     the route exists (or matches paths[]). No extra wiring needed.
*/

$isProduction        = env('APP_ENV') === 'production';
$allowedExtensionIds = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('ALLOWED_EXTENSION_IDS', ''))
)));

// Build the origin-allow regex. In production we hard-code the allowed IDs
// into the pattern so any other extension ID is rejected at the CORS layer
// before reaching any controller logic.
if ($isProduction) {
    $patterns = [];
    foreach ($allowedExtensionIds as $id) {
        // Quote the ID so any user-supplied regex metacharacters can't break
        // out. Extension IDs are lowercase alphanumeric so this should never
        // be necessary, but defense in depth costs nothing.
        $patterns[] = '#^chrome-extension://' . preg_quote($id, '#') . '$#i';
    }
    $allowedOriginsPatterns = $patterns;
} else {
    // Dev: any chrome-extension://* and any http://localhost(:port)
    $allowedOriginsPatterns = [
        '#^chrome-extension://[a-z0-9]{32}$#i',
        '#^http://localhost(:\d+)?$#i',
        '#^http://127\.0\.0\.1(:\d+)?$#i',
    ];
}

return [

    'paths' => ['api/*', 'webhook/*', 'admin/*'],

    'allowed_methods' => ['*'],

    // We use allowed_origins_patterns exclusively (see above). Leave the
    // literal list empty so it never wildcard-matches.
    'allowed_origins' => [],

    'allowed_origins_patterns' => $allowedOriginsPatterns,

    // Matches the Node backend's allowed_headers list exactly. Content-Type
    // is essential for JSON POSTs; the X-* headers are our custom auth.
    'allowed_headers' => [
        'Content-Type',
        'X-Device-ID',
        'X-Client-Secret',
        'X-Admin-Secret',
    ],

    'exposed_headers' => [
        // Rate-limit headers from Laravel's throttle middleware (P4).
        // Exposing them lets the extension display "X remaining" if useful.
        'RateLimit',
        'RateLimit-Policy',
        'Retry-After',
    ],

    // Preflight cache lifetime. 1 hour is reasonable — short enough that an
    // origin allowlist change propagates quickly, long enough to save 99% of
    // OPTIONS round-trips.
    'max_age' => 3600,

    'supports_credentials' => false,

];
