<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\VerificationOutcome;
use App\Models\EmailVerification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Verification service — owns the 6-digit code lifecycle for subscription
 * restore (C3/H3 fix from Batch 2).
 *
 * Properties guaranteed:
 *   - Code stored hashed (sha256 hex), NEVER plaintext
 *   - 6-digit numeric, crypto-strong (rejection-sampled to avoid modulo bias)
 *   - 10-minute expiry
 *   - 5-attempt cap (after 5 failed attempts, the code is dead)
 *   - Single-use (consumed_at marked on first successful verify)
 *   - Atomic verify-and-consume (transaction prevents race on last attempt)
 *
 * Anti-spam (per-email pending-code cap) is enforced by the
 * SubscriptionController BEFORE calling create() — this service doesn't
 * know about it because it's a UX-level policy that may change.
 *
 * The plaintext code is returned ONCE from create() — the caller is
 * responsible for delivering it to the user via email. After that, only
 * the hash exists in the DB.
 */
final class VerificationService
{
    /** Verification lifetime in seconds. */
    private const TTL_SECONDS = 10 * 60;

    /** Hard cap on verification attempts per code. */
    private const MAX_ATTEMPTS = 5;

    /**
     * Create a new verification code.
     *
     * Returns the plaintext code so the caller can email it. After this
     * call returns, the plaintext is GONE — only the sha256 hash exists
     * in the DB.
     *
     * @return array{id: string, code: string, expires_at: string}
     */
    public function create(string $email, string $deviceId, string $purpose): array
    {
        $id        = (string) Str::uuid();
        $code      = $this->generateCode();
        $hash      = $this->hashCode($code);
        $expiresAt = now()->addSeconds(self::TTL_SECONDS)->toDateTimeString();

        EmailVerification::create([
            'id'         => $id,
            'email'      => $email,
            'device_id'  => $deviceId,
            'purpose'    => $purpose,
            'code_hash'  => $hash,
            'attempts'   => 0,
            'expires_at' => $expiresAt,
        ]);

        return [
            'id'         => $id,
            'code'       => $code,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Verify a submitted code against the latest usable verification for
     * (email, purpose). Atomic: the whole check + attempt-increment + consume
     * runs in one transaction so two concurrent submissions on the last
     * allowed attempt can't both bypass the lockout.
     *
     * On success, returns Success and exposes the device_id the code was
     * issued to (the SubscriptionController uses this to confirm the
     * verification was meant for this restore flow's owner).
     *
     * On failure, returns the specific reason for in-band logging. The
     * caller MUST translate all failure reasons to the same generic
     * user-visible message to prevent enumeration.
     *
     * @return array{outcome: VerificationOutcome, device_id?: string}
     */
    public function verify(string $email, string $purpose, string $submittedCode): array
    {
        return DB::transaction(function () use ($email, $purpose, $submittedCode): array {
            // Latest unconsumed unexpired code for this (email, purpose).
            // Use forUpdate so concurrent verifies serialise — without it,
            // two requests could both read the same attempts value and both
            // increment, bypassing the MAX_ATTEMPTS check.
            //
            // SQLite implements row-locking via the implicit transaction
            // serialisation. forUpdate is a no-op on SQLite but documents
            // intent and Just Works when we eventually move to Postgres.
            $row = EmailVerification::query()
                ->whereRaw('LOWER(email) = LOWER(?)', [$email])
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now()->toDateTimeString())
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            // No usable code. Could mean: never requested, expired, or
            // already consumed. Caller maps to the same generic message.
            if ($row === null) {
                return ['outcome' => VerificationOutcome::NotFound];
            }

            if ($row->attempts >= self::MAX_ATTEMPTS) {
                return ['outcome' => VerificationOutcome::TooManyAttempts];
            }

            $submittedHash = $this->hashCode($submittedCode);

            // Timing-safe comparison via hash_equals. Both hashes are 64-char
            // sha256 hex so length comparison is constant.
            if (! hash_equals($row->code_hash, $submittedHash)) {
                // Wrong code — burn an attempt.
                $row->increment('attempts');

                // If the increment pushed us to the cap, surface that to
                // the caller so they get a "too many attempts" message
                // rather than another "invalid".
                if ($row->attempts + 1 >= self::MAX_ATTEMPTS) {
                    return ['outcome' => VerificationOutcome::TooManyAttempts];
                }
                return ['outcome' => VerificationOutcome::InvalidCode];
            }

            // Correct code — consume it. Single-use.
            $row->update(['consumed_at' => now()->toDateTimeString()]);

            return [
                'outcome'    => VerificationOutcome::Success,
                'device_id'  => $row->device_id,
            ];
        });
    }

    /**
     * Generate a 6-digit numeric code with crypto-strong randomness.
     *
     * Rejection-sampling against the top of uint32 to avoid modulo bias —
     * without rejection, the top 96 values (≈ 0.0023% of the space) would
     * be slightly less likely than the rest. Cryptographically irrelevant
     * for a 1-in-1M secret, but cheap to do right.
     */
    private function generateCode(): string
    {
        $max    = 1_000_000;
        $reject = intdiv(0xFFFFFFFF, $max) * $max;

        do {
            $bytes = random_bytes(4);
            // 4-byte big-endian unsigned int.
            $n = unpack('N', $bytes)[1];
        } while ($n >= $reject);

        return str_pad((string) ($n % $max), 6, '0', STR_PAD_LEFT);
    }

    private function hashCode(string $code): string
    {
        return hash('sha256', $code);
    }
}
