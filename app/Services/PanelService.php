<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Device;
use App\Models\PanelOpen;
use App\Models\Setting;
use App\Support\Limits;
use Illuminate\Support\Facades\DB;

/**
 * Panel-open gate service.
 *
 * Port of the Node backend's recordPanelOpen() from database.ts.
 *
 * Handles the per-device daily panel-open quota — which is separate from
 * the analyze/profile/score limits because:
 *   - It's atomically checked + recorded against the panel_opens table
 *     (composite PK enforces same-job-same-day uniqueness)
 *   - The response shape carries display copy (pricing, trial details)
 *     that the extension renders on upgrade CTAs
 *   - Same-job re-opens are FREE (don't consume quota), only distinct
 *     jobs count against the daily limit
 *
 * The whole gate (read tier → check existing → count → insert) MUST be
 * atomic — without a transaction, two concurrent panel opens on the
 * user's last allowed slot can both see usedToday == limit-1 and both
 * insert, exceeding the limit by one.
 */
final class PanelService
{
    /**
     * Check whether the device may open a panel for the given job, and if
     * so, record the open. Always returns a response payload describing
     * the result + display copy for the extension.
     *
     * @param  string  $deviceId — Resolved Device's UUID (from $request->device())
     * @param  string  $jobId    — Non-empty job ID (caller has already rejected empty)
     * @return array<string, mixed>  Matches the Node PanelOpenResult shape
     */
    public function recordOpen(Device $device, string $jobId): array
    {
        // Empty jobId is rejected at the controller layer (PanelController).
        // The PanelOpen model also throws on empty in its writes.
        if ($jobId === '') {
            throw new \InvalidArgumentException(
                'PanelService::recordOpen: jobId is required (controller must validate)'
            );
        }

        return DB::transaction(function () use ($device, $jobId): array {
            $tier            = $device->effectiveTier();
            $trialAvailable  = $device->trialAvailable();
            $limit           = Limits::PANEL_LIMITS[$tier];
            $displayCopy     = $this->buildDisplayCopy($trialAvailable);

            // ── Pro tier: unlimited, just record for analytics ─────────────
            if ($tier === 'pro') {
                $this->insertOpen($device->id, $jobId);
                return array_merge([
                    'allowed'       => true,
                    'alreadyOpened' => false,
                    'usedToday'     => 0,
                    'limit'         => null,
                    'trial'         => false,
                    'trialDaysLeft' => null,
                    'resetAt'       => null,
                    'needs_upgrade' => false,
                ], $displayCopy);
            }

            // ── Free / trial: check same-job re-open (free slot) ──────────
            $alreadyOpenedToday = PanelOpen::existsForDevice($device->id, $jobId);
            if ($alreadyOpenedToday) {
                return array_merge([
                    'allowed'       => true,
                    'alreadyOpened' => true,
                    'usedToday'     => PanelOpen::countToday($device->id),
                    'limit'         => $limit,
                    'trial'         => $tier === 'trial',
                    'trialDaysLeft' => $device->trialDaysLeft(),
                    'resetAt'       => null,
                    'needs_upgrade' => false,
                ], $displayCopy);
            }

            $usedToday = PanelOpen::countToday($device->id);

            // ── Limit hit ──────────────────────────────────────────────────
            if ($limit !== null && $usedToday >= $limit) {
                return array_merge([
                    'allowed'       => false,
                    'alreadyOpened' => false,
                    'usedToday'     => $usedToday,
                    'limit'         => $limit,
                    'trial'         => $tier === 'trial',
                    'trialDaysLeft' => $device->trialDaysLeft(),
                    'resetAt'       => $this->nextMidnightUtc(),
                    'needs_upgrade' => Setting::subscriptionsEnabled(),
                ], $displayCopy);
            }

            // ── Within limit — record and allow ────────────────────────────
            $this->insertOpen($device->id, $jobId);
            return array_merge([
                'allowed'       => true,
                'alreadyOpened' => false,
                'usedToday'     => $usedToday + 1,
                'limit'         => $limit,
                'trial'         => $tier === 'trial',
                'trialDaysLeft' => $device->trialDaysLeft(),
                'resetAt'       => null,
                'needs_upgrade' => false,
            ], $displayCopy);
        });
    }

    /**
     * Build the failure-mode response shape returned when recordOpen()
     * itself throws (e.g. DB connection lost). The Node backend's
     * /api/panel/open endpoint deliberately fails OPEN (HTTP 200, allowed
     * = true) so a DB outage doesn't lock a paying user out of their
     * panels.
     *
     * @return array<string, mixed>
     */
    public function buildFailOpenResponse(): array
    {
        return array_merge([
            'allowed'       => true,
            'alreadyOpened' => false,
            'usedToday'     => 0,
            'limit'         => null,
            'trial'         => false,
            'trialDaysLeft' => null,
            'resetAt'       => null,
            'needs_upgrade' => false,
        ], $this->buildDisplayCopy(false));
    }

    // ────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Insert a panel_opens row via INSERT OR IGNORE — the composite PK
     * (device_id, date, job_id) silently no-ops on duplicate.
     *
     * Note: writes via DB::statement because Eloquent doesn't support
     * composite PKs (see PanelOpen model docblock). Run inside the
     * recordOpen() transaction so concurrent calls serialise.
     */
    private function insertOpen(string $deviceId, string $jobId): void
    {
        DB::statement(
            "INSERT OR IGNORE INTO panel_opens (device_id, date, job_id) VALUES (?, date('now'), ?)",
            [$deviceId, $jobId]
        );
    }

    /**
     * Display-copy bundle embedded in every panel-check response so the
     * extension renders upgrade CTAs without hardcoding strings. All
     * values trace back to Limits — change a price there and every panel
     * on the next open reflects it.
     *
     * @return array{pricing: array<string, mixed>, trial_available: bool, trial_duration_days: int, trial_limits: array{panel: int|null, analyze: int|null}}
     */
    private function buildDisplayCopy(bool $trialAvailable): array
    {
        return [
            'pricing'             => Limits::PRICING,
            'trial_available'     => $trialAvailable,
            'trial_duration_days' => Limits::TRIAL_DAYS,
            'trial_limits'        => [
                'panel'   => Limits::LIMITS['trial']['panel'],
                'analyze' => Limits::LIMITS['trial']['analyze'],
            ],
        ];
    }

    private function nextMidnightUtc(): string
    {
        return now()->utc()->addDay()->startOfDay()->toIso8601String();
    }
}
