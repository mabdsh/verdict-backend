<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Device;
use Illuminate\Support\Facades\DB;

/**
 * Subscription service — currently scoped to the device-migration step of
 * the restore flow.
 *
 * Restore flow (SubscriptionController::restoreConfirm):
 *   1. Verify the 6-digit code (delegated to VerificationService)
 *   2. Look up the existing device by canonical email
 *   3. If the existing device IS the same as the requesting device, no-op
 *   4. Otherwise, atomically MIGRATE — revoke the old, apply to the new
 *
 * This service owns step 4. Atomicity matters: a crash between the two
 * UPDATEs would leave the paying customer with a revoked old device AND
 * no subscription on the new one — a worst-case outcome that requires
 * manual DB intervention to recover.
 *
 * The Node version had the same transaction wrapping in
 * subscriptionRouter.ts /restore/confirm.
 */
final class SubscriptionService
{
    /**
     * Migrate an active subscription from one device to another. Both
     * devices must exist; the source must hold the subscription rows.
     * No-op if $from->id === $to->id.
     *
     * The transaction:
     *   1. Revoke the OLD device — clear tier + subscription fields back to free
     *   2. Apply the subscription to the NEW device, copying every relevant
     *      field across including email so canonical-email lookups continue
     *      to find the right device
     *
     * Locks both rows for update — without it, a concurrent webhook update
     * to either device could land between the two UPDATEs and overwrite a
     * field we just set.
     */
    public function migrateToDevice(Device $from, Device $to): void
    {
        if ($from->id === $to->id) {
            return; // same device — restore is a no-op
        }

        DB::transaction(function () use ($from, $to): void {
            // Re-read both inside the transaction with row locks. Carry the
            // subscription fields across via local variables so the writes
            // see consistent values even if a webhook fires mid-transaction.
            $source = Device::query()->lockForUpdate()->find($from->id);
            $target = Device::query()->lockForUpdate()->find($to->id);

            if ($source === null || $target === null) {
                // Should never happen — caller verified both before us.
                // If it does, the transaction rolls back and the migration
                // is treated as failed by the controller.
                throw new \RuntimeException(
                    'SubscriptionService::migrateToDevice: device disappeared mid-migration'
                );
            }

            // Snapshot the subscription state from the source.
            $email     = $source->email;
            $tier      = $source->tier;
            $subId     = $source->subscription_id;
            $status    = $source->subscription_status;
            $endsAt    = $source->subscription_ends_at;

            // Revoke the OLD device — one active device per subscription.
            $source->update([
                'tier'                 => 'free',
                'subscription_id'      => null,
                'subscription_status'  => null,
                'subscription_ends_at' => null,
            ]);

            // Apply to the NEW device. Clear tier_override on the new device
            // so the standard tier-resolution priority order applies — an
            // admin override on the old device shouldn't carry across.
            $target->update([
                'email'                => $email,
                'tier'                 => $tier,
                'tier_override'        => null,
                'subscription_id'      => $subId,
                'subscription_status'  => $status,
                'subscription_ends_at' => $endsAt,
            ]);
        });
    }
}
