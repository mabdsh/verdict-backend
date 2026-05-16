<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EmailKind;
use App\Enums\VerificationOutcome;
use App\Http\Requests\RestoreConfirmRequest;
use App\Http\Requests\RestoreStartRequest;
use App\Models\Device;
use App\Models\EmailVerification;
use App\Models\PanelOpen;
use App\Models\Setting;
use App\Models\Usage;
use App\Services\EmailService;
use App\Services\SubscriptionService;
use App\Services\VerificationService;
use App\Support\EmailHelper;
use App\Support\Limits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Subscription status + restore endpoints.
 *
 * Port of the Node backend's subscriptionRouter.ts.
 *
 * Four methods on this controller:
 *
 *   status            — GET /api/subscription/status
 *   restoreDeprecated — POST /api/subscription/restore         (HTTP 410)
 *   restoreStart      — POST /api/subscription/restore/start   (sends code)
 *   restoreConfirm    — POST /api/subscription/restore/confirm (verifies + migrates)
 *
 * These four are kept on one controller (rather than four __invoke classes)
 * because they share helpers, share the same conceptual area (subscription
 * lifecycle), and the restoreStart/restoreConfirm pair only makes sense as
 * a two-call dance.
 */
final class SubscriptionController
{
    /**
     * Max pending (unconfirmed, unexpired) verification codes per email.
     * Prevents an attacker email-bombing a victim by spamming restoreStart.
     * Enforced INSIDE the constant-200 flow so the response shape doesn't
     * leak whether the cap was hit.
     */
    private const MAX_PENDING_RESTORE_CODES = 3;

    public function __construct(
        private readonly VerificationService $verification,
        private readonly SubscriptionService $subscriptions,
        private readonly EmailService       $email,
    ) {}

    // ────────────────────────────────────────────────────────────────────────
    // GET /api/subscription/status
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Returns the complete subscription/usage/limits state for the requesting
     * device. The extension popup reads ~15 fields off this response to
     * render the dashboard.
     *
     * Hot path — called every time the popup opens. The audit (M4) flagged
     * this for in-memory caching; for now it does fresh reads. When we ever
     * see latency pressure we'll add a 30-second LRU here.
     */
    public function status(Request $request): JsonResponse
    {
        $device = $request->deviceOrFail();
        return response()->json($this->buildStatusResponse($device));
    }

    /**
     * Build the GET /status response payload. Extracted for testability and
     * for future use by an admin "preview as device" endpoint.
     *
     * @return array<string, mixed>
     */
    private function buildStatusResponse(Device $device): array
    {
        $subsEnabled = Setting::subscriptionsEnabled();
        $tier        = $subsEnabled ? $device->effectiveTier() : 'pro';

        // Usage today — usage table may not have a row for today yet on
        // brand-new devices. usageToday() lazily creates a zero row.
        $usage      = $device->usageToday();
        $panelOpens = PanelOpen::countToday($device->id);

        $trialDaysLeft     = null;
        if ($tier === 'trial' && $device->trial_started_at !== null) {
            $trialDaysLeft = $device->trialDaysLeft();
        }

        // Build the limits object from the single source of truth.
        // Note: score limit is exposed here (the Node version had it as null
        // — this is a small port-time bug fix; the extension's UI gracefully
        // handles either value via `limits.score ?? null`).
        $limits = [
            'panel'   => Limits::PANEL_LIMITS[$tier]            ?? null,
            'analyze' => Limits::CALL_LIMITS['analyze'][$tier]  ?? null,
            'profile' => Limits::CALL_LIMITS['profile'][$tier]  ?? null,
            'score'   => Limits::SCORE_LIMITS[$tier]            ?? null,
        ];

        return [
            'tier' => $tier,

            // Trial state.
            // `trial_available` gates the "Start trial" CTA in the popup —
            // once a device has ANY trial_started_at value (active OR
            // expired), they can never start another.
            'trial_activated'      => $device->trial_started_at !== null,
            'trial_available'      => $device->trial_started_at === null,
            'trial_days_left'      => $trialDaysLeft,
            'trial_duration_days'  => Limits::TRIAL_DAYS,

            'subscriptions_enabled' => $subsEnabled,
            'subscription_status'   => $device->subscription_status,
            'subscription_ends_at'  => $device->subscription_ends_at?->toIso8601String(),

            'limits'      => $limits,
            'usage_today' => [
                'panel'   => $panelOpens,
                'analyze' => $usage->analyze_calls,
                'profile' => $usage->profile_calls,
                'scored'  => $usage->jobs_scored,
            ],

            // Tier copy + pricing — single source of truth lives in Limits.
            // The popup reads from here so price or feature changes need
            // only one edit (app/Support/Limits.php) and propagate to every
            // install on next status fetch.
            'tiers'   => Limits::tierCopyWithPricing(),
            'pricing' => Limits::PRICING,
        ];
    }

    // ────────────────────────────────────────────────────────────────────────
    // POST /api/subscription/restore (DEPRECATED)
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Old single-call restore endpoint, deprecated in Batch 2 in favour of
     * the verified two-call flow. Returns HTTP 410 with a clear message so
     * old extension installs see something actionable in the popup instead
     * of a 404. Removed in a future phase once Chrome Web Store install
     * metrics show old extension versions are below ~1%.
     */
    public function restoreDeprecated(): JsonResponse
    {
        return response()->json([
            'ok'      => false,
            'error'   => 'client_upgrade_required',
            'message' => 'Please update the Verdict extension to the latest version to restore your subscription.',
        ], 410);
    }

    // ────────────────────────────────────────────────────────────────────────
    // POST /api/subscription/restore/start
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Step 1 of 2 for restoring a subscription to a new device.
     *
     * Generates a 6-digit verification code, sends it to the address
     * (stub-logged for now — Phase 7 stops at EmailService stub; real
     * outbound mail in a later batch), and ALWAYS returns 200 with the
     * SAME response body regardless of whether the email is a real
     * customer.
     *
     * This is the cryptographically important part — prevents email
     * enumeration. An attacker calling /restore/start can't tell which
     * case happened:
     *   - Email is real + restorable + under pending cap  → code sent
     *   - Email is real + restorable + over pending cap   → code NOT sent
     *   - Email not in our system                          → nothing
     *   - Email in system but sub is expired/refunded      → nothing
     * The response body is identical in all four cases.
     */
    public function restoreStart(RestoreStartRequest $request): JsonResponse
    {
        $device = $request->deviceOrFail();
        $email  = (string) $request->input('email');

        // Constant-response branch: we always tell the caller "if this
        // address is associated with a Pro subscription, a code has been
        // sent." Look up the device but only actually create + send the
        // code if the subscription is restorable.
        $existing  = Device::findByEmail($email);
        $restorable = $existing !== null
            && in_array(
                $existing->subscription_status,
                ['active', 'cancelled', 'past_due'],
                true,
            );

        if ($restorable) {
            // Per-email anti-spam: cap concurrent unconfirmed codes.
            // Doesn't leak existence (only triggers if we'd actually send)
            // — the caller still sees the same generic 200 response.
            $pending = EmailVerification::pendingCountFor($email, 'restore');
            if ($pending < self::MAX_PENDING_RESTORE_CODES) {
                $v = $this->verification->create($email, $device->id, 'restore');
                $this->email->send(
                    EmailKind::RestoreVerification,
                    $email,
                    ['code' => $v['code']],
                );
                Log::info('[Restore] code created', [
                    'email'   => EmailHelper::mask($email),
                    'code_id' => $v['id'],
                ]);
            } else {
                Log::warning('[Restore] suppressed code (pending cap)', [
                    'email'   => EmailHelper::mask($email),
                    'pending' => $pending,
                ]);
            }
        } else {
            Log::info('[Restore] no restorable subscription', [
                'email' => EmailHelper::mask($email),
            ]);
        }

        // Always 200, same shape, same message — whether or not the email
        // matched. This is the enumeration-safety guarantee.
        return response()->json([
            'ok'      => true,
            'message' => 'If this email is associated with a Pro subscription, a 6-digit verification code has been sent. The code expires in 10 minutes.',
        ]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // POST /api/subscription/restore/confirm
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Step 2 of 2. Caller submits the email + 6-digit code from the email.
     * On success, the subscription is migrated to this device atomically.
     *
     * The verify call is single-use (code is marked consumed on first
     * success), constant-time on the code-hash comparison, and returns
     * specific failure reasons (expired / too_many_attempts / invalid_code
     * / not_found). ALL non-success paths use the same generic message to
     * the user — different messages would let an attacker enumerate
     * verification state via timing or status-message inspection.
     *
     * The specific reason is included in the JSON body as `reason` so the
     * extension can branch (e.g. show "expired — get a new code" vs "too
     * many attempts — try again later") without leaking it via the
     * human-readable message.
     */
    public function restoreConfirm(RestoreConfirmRequest $request): JsonResponse
    {
        $device = $request->deviceOrFail();
        $email  = (string) $request->input('email');
        $code   = (string) $request->input('code');

        $result = $this->verification->verify($email, 'restore', $code);

        if ($result['outcome'] !== VerificationOutcome::Success) {
            return response()->json([
                'ok'      => false,
                'error'   => 'verification_failed',
                'reason'  => $result['outcome']->value,
                'message' => 'Verification failed. Please request a new code.',
            ], 400);
        }

        // Verified — now do the actual subscription migration.
        $existing = Device::findByEmail($email);
        if ($existing === null) {
            // Race: subscription was cancelled-and-deleted between
            // /start and /confirm. Vanishingly rare; tell the user honestly.
            return response()->json([
                'ok'      => false,
                'error'   => 'not_found',
                'message' => 'Subscription is no longer available for restore.',
            ], 404);
        }

        $this->subscriptions->migrateToDevice($existing, $device);

        // Re-read the device to compute the effective tier post-migration.
        $device->refresh();
        $restoredTier = $device->effectiveTier();

        Log::info('[Restore] migration successful', [
            'email'      => EmailHelper::mask($email),
            'device_id'  => substr($device->id, 0, 8),
            'restoredTier' => $restoredTier,
        ]);

        return response()->json([
            'ok'      => true,
            'message' => 'Subscription restored successfully.',
            'tier'    => $restoredTier,
        ]);
    }
}
