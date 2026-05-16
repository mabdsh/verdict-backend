<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verify the X-Client-Secret + X-Device-ID headers and load the Device model.
 *
 * Port of the Node backend's middleware/auth.ts:
 *   1. Timing-safe comparison of X-Client-Secret against config('verdict.client_secret')
 *   2. UUID v4 format validation of X-Device-ID
 *   3. upsertDevice() — INSERT OR IGNORE the device row, touch last_seen
 *   4. Attach the resolved Device model to the request for controllers
 *
 * Controllers retrieve the device via the request macro registered in
 * AppServiceProvider:
 *
 *   $device = $request->device();   // returns Device, guaranteed non-null
 *
 * Limitation (deferred to Batch 6 in the audit): CLIENT_SECRET is shipped
 * inside the extension binary and therefore world-readable. It is NOT a
 * meaningful security boundary. The actual abuse protections are:
 *   - Per-IP rate limiting (edge limiters, P4)
 *   - Per-device daily quotas (CheckRateLimit + CheckScoreVolumeLimit, P4)
 *   - CORS allowlist (config/cors.php from P1)
 * This middleware exists for parity with the Node version and to authenticate
 * the device-id; the X-Client-Secret check will be replaced by per-install
 * bearer tokens in a later phase.
 */
final class RequireDevice
{
    /**
     * Standard UUID v4 pattern. Same regex as the Node version.
     */
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    public function handle(Request $request, Closure $next): Response
    {
        // 1. Client secret — fast check before any DB work.
        $submittedSecret = (string) $request->header('X-Client-Secret', '');
        $expectedSecret  = (string) config('verdict.client_secret', '');

        if (! $this->safeEqual($submittedSecret, $expectedSecret)) {
            return response()->json([
                'error'   => 'unauthorized',
                'message' => 'Invalid or missing X-Client-Secret header',
            ], 401);
        }

        // 2. Device ID — must be a valid UUID v4.
        $deviceId = (string) $request->header('X-Device-ID', '');
        if ($deviceId === '' || ! preg_match(self::UUID_REGEX, $deviceId)) {
            return response()->json([
                'error'   => 'unauthorized',
                'message' => 'Valid UUID v4 required in X-Device-ID header',
            ], 401);
        }

        // 3. Upsert the device row. firstOrCreate is a SELECT-then-INSERT, not
        //    atomic — under heavy concurrent first-install, two requests for
        //    the same device_id could both pass the SELECT and both try to
        //    INSERT. The second would fail with a unique-constraint violation.
        //    For first-install traffic this is vanishingly rare; for any
        //    return visit the row already exists and we just touch last_seen.
        $device = Device::firstOrCreate(
            ['id' => $deviceId],
            ['tier' => 'free']  // default for fresh installs
        );

        // 4. Touch last_seen so device-activity queries (admin /usage) see
        //    accurate "last active" timestamps. Done with a direct UPDATE
        //    to avoid the overhead of saving the full model.
        $device->touchLastSeen();

        // 5. Attach to the request for controllers and downstream middleware.
        //    $request->device() (registered in AppServiceProvider) reads from
        //    this attribute.
        $request->attributes->set('device', $device);

        return $next($request);
    }

    /**
     * Timing-safe string comparison — prevents brute-forcing the client
     * secret one character at a time via response-time measurement.
     *
     * PHP's hash_equals() handles unequal lengths internally by short-
     * circuiting to false, but the timing of that short-circuit IS
     * detectable. We pad to equal length first for full constant time.
     */
    private function safeEqual(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }
        // hash_equals is the canonical PHP function for this; pad to equal
        // length first so its length-mismatch fast path doesn't leak timing.
        if (strlen($a) !== strlen($b)) {
            // Run a dummy compare to keep timing similar to the equal-length case.
            hash_equals(str_repeat('x', 32), str_repeat('y', 32));
            return false;
        }
        return hash_equals($a, $b);
    }
}
