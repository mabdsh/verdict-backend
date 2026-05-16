<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TrialActivationResult;
use App\Http\Requests\ActivateTrialRequest;
use App\Services\TrialService;
use App\Support\EmailHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * POST /api/trial/activate
 *
 * Port of the Node backend's trialRouter.ts.
 *
 * Anti-abuse layers (in order of evaluation):
 *   1. Email format validation (FormRequest with strict regex)
 *   2. Disposable-domain blocklist check (EmailHelper::isDisposable, reads
 *      operator-mutable settings.disposable_email_domains)
 *   3. Canonicalization at the service layer — strips +tags and (for Gmail)
 *      dots so foo+1@gmail and f.oo+2@gmail count as the same trial owner
 *
 * Returns:
 *   ok: true,  already_active: false  → trial freshly activated
 *   ok: true,  already_active: true   → trial was already running on device
 *   ok: false, error: 'TRIAL_USED'    → this email already had a trial
 *   ok: false, error: 'EMAIL_NOT_ALLOWED'  → disposable email blocked
 */
final class TrialController
{
    public function __construct(
        private readonly TrialService $trial,
    ) {}

    public function __invoke(ActivateTrialRequest $request): JsonResponse
    {
        $device = $request->deviceOrFail();
        $email  = (string) $request->input('email');

        if (EmailHelper::isDisposable($email)) {
            // Generic message — don't tell the user we maintain a blocklist
            // (they'll just rotate domains). "Please use your real email"
            // is suggestive without being a list-of-words for them to grep
            // against.
            Log::warning('[Trial] blocked disposable email', [
                'email' => EmailHelper::mask($email),
            ]);
            return response()->json([
                'ok'      => false,
                'error'   => 'EMAIL_NOT_ALLOWED',
                'message' => 'Please use a non-disposable email address.',
            ], 400);
        }

        try {
            $result = $this->trial->activate($device, $email);
        } catch (Throwable $e) {
            Log::error('[Trial] activation failed', [
                'error' => $e->getMessage(),
                'email' => EmailHelper::mask($email),
            ]);
            return response()->json([
                'ok'      => false,
                'error'   => 'SERVER_ERROR',
                'message' => 'Could not activate trial — please try again.',
            ], 500);
        }

        return match ($result) {
            TrialActivationResult::AlreadyActive => $this->respondAlreadyActive($device->id),
            TrialActivationResult::EmailUsed     => $this->respondEmailUsed($email),
            TrialActivationResult::Activated     => $this->respondActivated($device->id, $email),
        };
    }

    // ────────────────────────────────────────────────────────────────────────
    // Response builders
    // ────────────────────────────────────────────────────────────────────────

    private function respondAlreadyActive(string $deviceId): JsonResponse
    {
        Log::info('[Trial] already active', [
            'device_id' => substr($deviceId, 0, 8),
        ]);
        return response()->json([
            'ok'             => true,
            'already_active' => true,
            'message'        => 'Trial already active.',
        ]);
    }

    private function respondEmailUsed(string $email): JsonResponse
    {
        // Return a generic message — don't confirm whether the email is
        // already in our system (that would be enumeration). The user-
        // visible message just says "this email has been used before."
        Log::info('[Trial] email already used', [
            'email' => EmailHelper::mask($email),
        ]);
        return response()->json([
            'ok'      => false,
            'error'   => 'TRIAL_USED',
            'message' => 'A trial has already been used with this email address.',
        ], 400);
    }

    private function respondActivated(string $deviceId, string $email): JsonResponse
    {
        Log::info('[Trial] activated', [
            'device_id' => substr($deviceId, 0, 8),
            'email'     => EmailHelper::mask($email),
        ]);
        return response()->json([
            'ok'             => true,
            'already_active' => false,
            'message'        => 'Trial activated. Enjoy your 7 days!',
        ]);
    }
}
