<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Write a row to request_logs for every API request that passes through.
 *
 * Port of the Node backend's logRequest() helper. This is what feeds the
 * /admin/logs and /admin/latency dashboards in P9.
 *
 * Implementation notes:
 *   - Runs BEFORE the response is generated (start timer), AFTER it returns
 *     (measure latency, capture status).
 *   - Reads device_id from the request attribute (set by RequireDevice).
 *     For requests that fail RequireDevice (401), device_id is null in the
 *     log row — still useful for noticing auth-failure spikes.
 *   - The DB write is best-effort: if it fails, log and continue. We must
 *     never let a logging failure break a successful API response.
 *   - The request gets a correlation ID (UUID v4) assigned here that's also
 *     attached to the response as the X-Request-ID header so it can be
 *     cross-referenced when debugging customer reports.
 */
final class LogRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();
        $request->attributes->set('request_id', $requestId);

        $startMs = (int) (microtime(true) * 1000);

        $response = $next($request);

        $latencyMs = (int) (microtime(true) * 1000) - $startMs;

        $this->writeLog($request, $response, $requestId, $latencyMs);

        // Expose the correlation ID on the response — easy to grep server
        // logs for if a user reports an issue and forwards a screenshot.
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }

    private function writeLog(Request $request, Response $response, string $requestId, int $latencyMs): void
    {
        try {
            /** @var Device|null $device */
            $device   = $request->attributes->get('device');
            $deviceId = $device?->id;

            $status   = $response->getStatusCode();
            $error    = $this->extractErrorCode($response);

            // request_logs.endpoint stores the URI path WITHOUT query string.
            // Same as the Node version which logs e.g. '/api/score/batch'.
            $endpoint = '/' . ltrim($request->path(), '/');

            DB::statement(
                'INSERT INTO request_logs (id, device_id, endpoint, latency_ms, status, error)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$requestId, $deviceId, $endpoint, $latencyMs, $status, $error]
            );
        } catch (Throwable $e) {
            // Logging must NEVER cause the request to fail. Surface to the
            // application log so the issue is visible without breaking
            // the user's request.
            Log::error('[LogRequest] Failed to persist request log', [
                'error'      => $e->getMessage(),
                'request_id' => $requestId,
            ]);
        }
    }

    /**
     * Extract a short error code from a JSON response body, if present.
     * Stored in request_logs.error so /admin/logs?errors=true returns
     * usable codes rather than full message text.
     *
     * Returns null on success (status < 400) or if no error code can be
     * extracted.
     */
    private function extractErrorCode(Response $response): ?string
    {
        if ($response->getStatusCode() < 400) {
            return null;
        }

        $content = $response->getContent();
        if (! is_string($content) || $content === '') {
            return 'unknown';
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            return 'unknown';
        }

        // Prefer a structured 'error' field. Fall back to the status code.
        $code = $decoded['error'] ?? null;
        if (is_string($code) && $code !== '') {
            return substr($code, 0, 64);  // schema limit
        }

        return 'unknown';
    }
}
