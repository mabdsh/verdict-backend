<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PanelService;
use App\Support\Limits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * POST /api/panel/open
 *
 * Port of the Node backend's panelRouter.ts. Checks whether the device may
 * open the panel for a given job, and if so, records the open.
 *
 * Always returns HTTP 200 — the extension reads the `allowed` field of the
 * JSON body, not the HTTP status, to decide what to show. Failing at the
 * HTTP level (5xx) means the extension gets no response and falls back to
 * opening the panel anyway (fail-open is INTENTIONAL).
 *
 * Middleware applied by routes/api.php:
 *   - require.device
 *   - throttle:panelEdge
 *
 * No daily-cap middleware here — the cap is checked atomically inside
 * PanelService::recordOpen() because its semantics are
 * "same job re-opens are free, distinct jobs count" which can't be
 * expressed as a simple +1 increment.
 */
final class PanelController
{
    /** LinkedIn/Indeed job IDs are at most ~20 chars. Cap at 64 as a safe
     *  ceiling to prevent a caller sending a multi-MB string that gets
     *  written to panel_opens and read back on every panel-count query. */
    private const MAX_JOB_ID_LENGTH = 64;

    public function __construct(
        private readonly PanelService $panel,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $rawJobId = $request->input('jobId');
        $jobId    = is_string($rawJobId)
            ? substr(trim($rawJobId), 0, self::MAX_JOB_ID_LENGTH)
            : '';

        // Empty jobId → 400. Previously (in Node before Batch 2's M10 fix)
        // an empty jobId got a random UUID substituted, which broke the
        // (device, date, jobId) dedup PK and let a misbehaving caller
        // consume multiple slots from the daily quota for what should be
        // the same panel open.
        if ($jobId === '') {
            return response()->json([
                'error'   => 'invalid_input',
                'message' => 'jobId is required',
            ], 400);
        }

        $device = $request->deviceOrFail();

        try {
            $result = $this->panel->recordOpen($device, $jobId);
            return response()->json($result);
        } catch (Throwable $e) {
            // Fail open — a server-side error must never block a user from
            // their panel. We still attach the display-copy bundle so the
            // content script never has to handle an undefined pricing /
            // trial_limits object.
            Log::error('[Panel] recordOpen error', [
                'device_id' => $device->id,
                'error'     => $e->getMessage(),
            ]);
            return response()->json($this->panel->buildFailOpenResponse());
        }
    }
}
