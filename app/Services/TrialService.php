<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TrialActivationResult;
use App\Models\Device;
use App\Support\EmailHelper;
use Illuminate\Support\Facades\DB;

/**
 * Trial activation service.
 *
 * Port of the Node backend's activateTrial() from database.ts.
 *
 * The activation is atomic: the whole sequence (check device's existing
 * trial → check no other device used this canonical email for a trial →
 * UPDATE device with trial_started_at) runs inside a DB::transaction so
 * two near-simultaneous requests with the same email on different devices
 * can't both pass the dedup check and both activate trials.
 *
 * Canonical-email dedup is the C4 fix from Batch 2:
 *   foo+1@gmail.com and foo+anything@gmail.com both map to foo@gmail.com,
 *   so the one-trial-per-email rule isn't bypassable via plus-aliases or
 *   (for Gmail) dot variations.
 *
 * Disposable-email blocking is enforced by the controller (TrialController)
 * BEFORE calling this service. The blocklist lives in the settings table
 * and is operator-mutable — that's a UX policy decision, not a domain
 * invariant of trial activation, so it doesn't belong here.
 */
final class TrialService
{
    public function activate(Device $device, string $email): TrialActivationResult
    {
        $canonical = EmailHelper::canonicalize($email);

        // Defensive: malformed input. Caller's validator should have rejected
        // this, but treat empty canonical as "already used" so we never leak
        // information about whether the malformed address is a customer.
        if ($canonical === '') {
            return TrialActivationResult::EmailUsed;
        }

        return DB::transaction(function () use ($device, $canonical): TrialActivationResult {
            // Re-read the device row inside the transaction to pick up any
            // concurrent writes (e.g. another request that activated trial
            // 1ms ago). Idempotent — if trial is already active on this
            // device, return that without changing state.
            $fresh = Device::query()->lockForUpdate()->find($device->id);
            if ($fresh === null || $fresh->trial_started_at !== null) {
                return TrialActivationResult::AlreadyActive;
            }

            // One trial per canonical email address. We check the stored
            // canonical form against canonical(input) so legacy raw emails
            // saved before this change still dedup correctly as long as
            // new lookups canonicalize on both sides.
            //
            // The lookup compares LOWER(email) — Device::email is stored
            // lowercase by all writers, so this is effectively an
            // index-using equality check (covered by idx_devices_email).
            $previousTrial = Device::query()
                ->whereRaw('LOWER(email) = LOWER(?)', [$canonical])
                ->whereNotNull('trial_started_at')
                ->exists();

            if ($previousTrial) {
                return TrialActivationResult::EmailUsed;
            }

            // Atomically set trial_started_at + email. COALESCE preserves
            // any existing email value on the device — defensive in case
            // the user previously saved an address via /api/device/email.
            $fresh->update([
                'trial_started_at' => now()->toDateTimeString(),
                'email'            => $canonical,
            ]);

            return TrialActivationResult::Activated;
        });
    }
}
