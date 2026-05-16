<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SaveDeviceEmailRequest;
use App\Support\EmailHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * POST /api/device/email
 *
 * Port of the Node backend's deviceRouter.ts. Stores an optional email on
 * the device record so we can send trial-end notifications and support
 * subscription restore by email.
 *
 * This endpoint does NOT start a trial — trial activation goes through
 * /api/trial/activate, which has additional checks (disposable-email
 * blocklist, canonical-email dedup). Saving an email here is a no-op for
 * tier resolution; it's purely a recovery hint for the admin "search by
 * email" UI.
 *
 * Single-action controller (__invoke) — matches the Phase 6 pattern for
 * endpoints that do exactly one thing.
 */
final class DeviceController
{
    public function __invoke(SaveDeviceEmailRequest $request): JsonResponse
    {
        $device = $request->deviceOrFail();
        $email  = (string) $request->input('email');

        try {
            // Direct UPDATE rather than ->save() — we only want to touch
            // the email column, not bump any other timestamps.
            $device->update(['email' => $email]);
        } catch (Throwable $e) {
            Log::error('[Device] failed to save email', [
                'error' => $e->getMessage(),
                'email' => EmailHelper::mask($email),
            ]);
            return response()->json([
                'ok'      => false,
                'error'   => 'SERVER_ERROR',
                'message' => 'Failed to save email — try again.',
            ], 500);
        }

        Log::info('[Device] email saved', [
            'device_id' => substr($device->id, 0, 8),
            'email'     => EmailHelper::mask($email),
        ]);

        return response()->json([
            'ok'      => true,
            'message' => 'Email saved.',
        ]);
    }
}
