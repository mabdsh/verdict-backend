<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\WebhookEvent;
use App\Support\EmailHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * LemonSqueezy webhook handler.
 *
 * Port of the Node backend's webhookRouter.ts. Receives subscription
 * lifecycle events and updates device tier accordingly.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THIS CONTROLLER MUST RECEIVE THE RAW REQUEST BODY.                       ║
 * ║                                                                          ║
 * ║ LemonSqueezy signs the literal bytes of the JSON body with HMAC-SHA256. ║
 * ║ If anything pre-parses the body and re-serialises (whitespace, key       ║
 * ║ ordering) the signature won't match.                                     ║
 * ║                                                                          ║
 * ║ Laravel's $request->getContent() returns the raw body string. We hash    ║
 * ║ that BEFORE accessing $request->input(), then json_decode it ourselves.  ║
 * ║ The route is registered in routes/webhook.php OUTSIDE the api middleware ║
 * ║ group — no CORS, no rate limit, no log.request middleware.               ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Idempotency:
 *   LemonSqueezy retries failed webhooks (non-2xx response or timeout) over
 *   ~24h. We dedupe by (provider, event_id) in webhook_events. A retry of a
 *   successfully-processed event is silently dropped with 200 OK so LS stops
 *   retrying. Without this, double-applying state changes can re-stamp
 *   past_due_at or undo a cancellation.
 *
 * Response codes:
 *   - 401 invalid_signature   — LS will NOT retry
 *   - 400 invalid_json        — LS will NOT retry
 *   - 200 received=true       — happy path, also for duplicates and ignored events
 *   - 500 internal_error      — LS WILL retry; transient DB error etc.
 */
final class LemonSqueezyWebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        // 1. Raw body for signature verification.
        $rawBody = $request->getContent();
        $signature = (string) $request->header('X-Signature', '');

        if (! $this->verifySignature($rawBody, $signature)) {
            Log::warning('[LS-Webhook] invalid signature');
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        // 2. Parse the JSON (signature already verified — safe to do now).
        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            Log::warning('[LS-Webhook] invalid JSON body');
            return response()->json(['error' => 'invalid_json'], 400);
        }

        // 3. Pull the standard LS payload fields.
        $eventName  = $payload['meta']['event_name']   ?? null;
        $customData = $payload['meta']['custom_data']  ?? [];
        $attrs      = $payload['data']['attributes']   ?? [];
        $subId      = (string) ($payload['data']['id'] ?? '');

        $deviceId = is_array($customData) ? ($customData['device_id'] ?? null) : null;
        $email    = is_array($attrs) ? ($attrs['user_email'] ?? null) : null;

        // 4. Idempotency guard — use the LS event_id (stable across retries).
        $eventId = (string) ($payload['meta']['event_id'] ?? $payload['data']['id'] ?? '');
        if ($eventId !== '') {
            $isNew = WebhookEvent::record('lemonsqueezy', $eventId, is_string($eventName) ? $eventName : null);
            if (! $isNew) {
                Log::info('[LS-Webhook] duplicate ignored', [
                    'event'      => $eventName,
                    'event_id'   => $eventId,
                    'sub_id'     => $subId,
                ]);
                return response()->json(['received' => true, 'action' => 'duplicate_ignored']);
            }
        }

        Log::info('[LS-Webhook] received', [
            'event'    => $eventName,
            'event_id' => $eventId,
            'sub_id'   => $subId,
            'device'   => is_string($deviceId) ? substr($deviceId, 0, 8) : 'unknown',
            'email'    => EmailHelper::mask(is_string($email) ? $email : null),
        ]);

        // 5. No device_id means this checkout wasn't initiated from our
        //    extension. Acknowledge but take no action. Returning 200 stops
        //    LS from retrying forever.
        if (! is_string($deviceId) || $deviceId === '') {
            return response()->json(['received' => true, 'action' => 'ignored_no_device_id']);
        }

        // 6. Dispatch to per-event handler. Unknown events return 200 with
        //    'ignored_unknown_event' so LS stops retrying — we don't want
        //    to fail forever on a new event type LS introduces.
        try {
            $action = $this->dispatch($eventName, [
                'deviceId'        => $deviceId,
                'email'           => is_string($email) ? $email : null,
                'subscriptionId'  => $subId,
                'attrs'           => is_array($attrs) ? $attrs : [],
            ]);
        } catch (Throwable $e) {
            // Don't burn the idempotency slot on a transient failure — LS
            // will retry. Log loudly so we notice.
            Log::error('[LS-Webhook] handler error (will retry)', [
                'event'    => $eventName,
                'event_id' => $eventId,
                'error'    => $e->getMessage(),
            ]);
            return response()->json([
                'error'   => 'internal_error',
                'message' => 'Webhook processing failed — please retry',
            ], 500);
        }

        return response()->json(['received' => true, 'action' => $action]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Signature verification
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Verify X-Signature header using HMAC-SHA256 of the raw body.
     * Uses hash_equals for timing-safe comparison.
     */
    private function verifySignature(string $rawBody, string $signature): bool
    {
        $secret = (string) config('verdict.lemonsqueezy.signing_secret');
        if ($secret === '' || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        // Both are hex strings of equal length (64 chars). hash_equals does
        // the timing-safe compare and returns false safely on length mismatch.
        return hash_equals($expected, $signature);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Event dispatch
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Route an event to its handler. Returns a short action description
     * for the success response body.
     *
     * @param  array{deviceId: string, email: string|null, subscriptionId: string, attrs: array<string, mixed>}  $ctx
     */
    private function dispatch(?string $eventName, array $ctx): string
    {
        return match ($eventName) {
            'subscription_created',
            'subscription_updated',
            'subscription_resumed' => $this->handleCreatedOrUpdated($ctx),

            'subscription_cancelled'         => $this->handleCancelled($ctx),
            'subscription_expired'           => $this->handleExpired($ctx),
            'subscription_payment_failed'    => $this->handlePaymentFailed($ctx),
            'subscription_payment_refunded'  => $this->handlePaymentRefunded($ctx),

            default => 'ignored_unknown_event',
        };
    }

    /**
     * Subscription created / updated / resumed. The `status` attribute is
     * the source of truth for tier — see tierForStatus().
     *
     * @param  array<string, mixed>  $ctx
     */
    private function handleCreatedOrUpdated(array $ctx): string
    {
        $status = $ctx['attrs']['status'] ?? 'active';
        $this->updateDeviceSubscription([
            'deviceId'       => $ctx['deviceId'],
            'email'          => $ctx['email'],
            'subscriptionId' => $ctx['subscriptionId'],
            'status'         => is_string($status) ? $status : 'active',
            'tier'           => $this->tierForStatus(is_string($status) ? $status : 'active'),
            'endsAt'         => $ctx['attrs']['ends_at'] ?? null,
        ]);
        return 'subscription_applied';
    }

    /**
     * User cancelled — stays Pro until billing period ends (ends_at set).
     *
     * @param  array<string, mixed>  $ctx
     */
    private function handleCancelled(array $ctx): string
    {
        $this->updateDeviceSubscription([
            'deviceId'       => $ctx['deviceId'],
            'email'          => $ctx['email'],
            'subscriptionId' => $ctx['subscriptionId'],
            'status'         => 'cancelled',
            'tier'           => 'pro', // still paid through period end
            'endsAt'         => $ctx['attrs']['ends_at'] ?? null,
        ]);
        return 'subscription_cancelled';
    }

    /**
     * Billing period ended after cancellation — downgrade to free.
     *
     * @param  array<string, mixed>  $ctx
     */
    private function handleExpired(array $ctx): string
    {
        $this->updateDeviceSubscription([
            'deviceId'       => $ctx['deviceId'],
            'email'          => $ctx['email'],
            'subscriptionId' => $ctx['subscriptionId'],
            'status'         => 'expired',
            'tier'           => 'free',
            'endsAt'         => null,
        ]);
        return 'subscription_expired';
    }

    /**
     * Payment failed — grace period, still Pro. past_due_at is stamped on
     * the FIRST failure only (preserved across LS dunning retries) so the
     * grace window measures from the first failure, not the most recent.
     *
     * @param  array<string, mixed>  $ctx
     */
    private function handlePaymentFailed(array $ctx): string
    {
        $this->updateDeviceSubscription([
            'deviceId'       => $ctx['deviceId'],
            'email'          => $ctx['email'],
            'subscriptionId' => $ctx['subscriptionId'],
            'status'         => 'past_due',
            'tier'           => 'pro',
            'endsAt'         => null,
        ]);
        return 'subscription_past_due';
    }

    /**
     * Payment refunded — revoke Pro immediately. We don't try to reason
     * about partial vs full refunds; LemonSqueezy controls the subscription
     * state. If money is going back, access stops.
     *
     * @param  array<string, mixed>  $ctx
     */
    private function handlePaymentRefunded(array $ctx): string
    {
        $this->updateDeviceSubscription([
            'deviceId'       => $ctx['deviceId'],
            'email'          => $ctx['email'],
            'subscriptionId' => $ctx['subscriptionId'],
            'status'         => 'refunded',
            'tier'           => 'free',
            'endsAt'         => null,
        ]);
        return 'subscription_refunded';
    }

    // ────────────────────────────────────────────────────────────────────────
    // Subscription state writer
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Atomic device-row update + past_due_at lifecycle management.
     *
     * Port of the Node backend's updateDeviceSubscription. Wrapped in a
     * transaction so the device-fields UPDATE and the past_due_at write
     * land together — without it, a crash between the two could leave
     * status=past_due and past_due_at=null, which Device::effectiveTier
     * treats as "trust LS, stay Pro" (the legacy-row codepath).
     *
     * past_due_at rules:
     *   - transition INTO past_due (and not already set) → stamp now
     *   - anything else                                  → clear
     * The "not already set" guard is what makes LS dunning retries safe.
     *
     * @param  array{deviceId: string, email: ?string, subscriptionId: string, status: string, tier: string, endsAt: ?string}  $params
     */
    private function updateDeviceSubscription(array $params): void
    {
        DB::transaction(function () use ($params): void {
            // Use COALESCE on email so a webhook that doesn't include
            // user_email doesn't clobber the existing value. The Node
            // version does the same.
            DB::statement(
                "UPDATE devices SET
                    email                = COALESCE(?, email),
                    tier                 = ?,
                    subscription_id      = ?,
                    subscription_status  = ?,
                    subscription_ends_at = ?,
                    last_seen            = datetime('now')
                 WHERE id = ?",
                [
                    $params['email'],
                    $params['tier'],
                    $params['subscriptionId'],
                    $params['status'],
                    $params['endsAt'],
                    $params['deviceId'],
                ]
            );

            if ($params['status'] === 'past_due') {
                // Stamp past_due_at on FIRST failure only — the IS NULL
                // guard preserves the original timestamp across LS retries.
                DB::statement(
                    "UPDATE devices SET past_due_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
                     WHERE id = ? AND past_due_at IS NULL",
                    [$params['deviceId']]
                );
            } else {
                // Any non-past_due status clears the stamp. Returning to
                // 'active' resets the grace clock for a future failure.
                DB::statement(
                    'UPDATE devices SET past_due_at = NULL WHERE id = ?',
                    [$params['deviceId']]
                );
            }
        });
    }

    /**
     * Map LemonSqueezy subscription status to our stored tier.
     *
     * Source of truth for status → tier. past_due and cancelled stay 'pro'
     * because Device::effectiveTier() needs tier='pro' to even reach the
     * grace-period / ends-at-in-future checks. Setting them to 'free' here
     * would skip those checks entirely and incorrectly revoke access.
     */
    private function tierForStatus(string $status): string
    {
        return match ($status) {
            'active'    => 'pro',  // paying and current
            'past_due'  => 'pro',  // payment failed, still in grace
            'cancelled' => 'pro',  // paid through ends_at
            'expired'   => 'free', // billing period ended
            'refunded'  => 'free', // payment refunded
            'paused'    => 'free', // subscription on hold
            default     => 'free', // unknown status — safe default
        };
    }
}
