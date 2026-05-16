<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\EmailVerification
 *
 * Short-lived verification codes used by subscription restore (C3/H3 fix
 * from Batch 2).
 *
 * Properties of the verification scheme:
 *   - Code stored as sha256 hex (NEVER plaintext) — DB leak doesn't expose
 *     active codes
 *   - 6-digit numeric, 10-minute expiry, 5-attempt cap, single-use
 *   - device_id binds the verification to the requesting device so a
 *     different device can't redeem an intercepted code
 *   - purpose discriminates flows ('restore' today, 'login' later, etc.)
 *
 * The actual generate/verify/consume logic lives in
 * App\Services\VerificationService (P5) because it needs to coordinate
 * with EmailService and run inside a transaction. This model is for
 * persistence and basic queries only.
 *
 * @property string                  $id
 * @property string                  $email
 * @property string                  $device_id
 * @property string                  $purpose
 * @property string                  $code_hash
 * @property int                     $attempts
 * @property CarbonImmutable         $created_at
 * @property CarbonImmutable         $expires_at
 * @property CarbonImmutable|null    $consumed_at
 */
final class EmailVerification extends Model
{
    protected $table = 'email_verifications';

    protected $primaryKey   = 'id';
    public    $incrementing = false;
    protected $keyType      = 'string';
    public    $timestamps   = false;

    protected $fillable = [
        'id', 'email', 'device_id', 'purpose', 'code_hash',
        'attempts', 'created_at', 'expires_at', 'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts'    => 'integer',
            'created_at'  => 'immutable_datetime',
            'expires_at'  => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }

    // ────────────────────────────────────────────────────────────────────────
    // State predicates
    // ────────────────────────────────────────────────────────────────────────

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isUsable(): bool
    {
        return ! $this->isExpired() && ! $this->isConsumed();
    }

    // ────────────────────────────────────────────────────────────────────────
    // Query scopes
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Restrict to verifications that could still be redeemed (unexpired AND
     * unconsumed). Used by the verify endpoint to find the candidate code.
     *
     * @param Builder<EmailVerification> $q
     */
    public function scopeUsable(Builder $q): Builder
    {
        return $q->whereNull('consumed_at')
            ->where('expires_at', '>', now()->toDateTimeString());
    }

    /**
     * Count outstanding (unexpired, unconsumed) codes for an email + purpose.
     * Used by SubscriptionController to enforce the per-email anti-spam cap
     * before issuing a fresh code.
     */
    public static function pendingCountFor(string $email, string $purpose): int
    {
        return static::query()
            ->usable()
            ->whereRaw('LOWER(email) = LOWER(?)', [$email])
            ->where('purpose', $purpose)
            ->count();
    }

    /**
     * Find the most recent usable verification for an (email, purpose).
     * The "most recent" tiebreaker matters because a user can request
     * multiple codes back-to-back (up to the anti-spam cap); we redeem
     * against the latest one.
     */
    public static function latestUsableFor(string $email, string $purpose): ?self
    {
        return static::query()
            ->usable()
            ->whereRaw('LOWER(email) = LOWER(?)', [$email])
            ->where('purpose', $purpose)
            ->orderByDesc('created_at')
            ->first();
    }
}
