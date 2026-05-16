<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * App\Models\Setting
 *
 * Generic key/value store for config that needs to be runtime-mutable
 * without a deploy. Examples:
 *
 *   subscriptions_enabled       — global paywall kill-switch
 *   disposable_email_domains    — CSV blocklist for trial activation
 *   admin_lockout_ip:*          — brute-force protection state (P9)
 *
 * Read-heavy: subscriptionsEnabled() is called on EVERY request that goes
 * through tier resolution. Caching with a short TTL is essential —
 * otherwise every request to a protected endpoint does an extra DB query.
 *
 * Cache invalidation: when admin toggles a setting via /admin/* (P9), the
 * controller MUST call Setting::forget($key) afterwards. We don't use
 * Eloquent observers for this because settings are also written by raw SQL
 * (e.g. admin lockout counters bypass the model layer for speed).
 *
 * @property string $key
 * @property string $value
 */
final class Setting extends Model
{
    protected $table = 'settings';

    protected $primaryKey   = 'key';
    public    $incrementing = false;
    protected $keyType      = 'string';
    public    $timestamps   = false;

    protected $fillable = ['key', 'value'];

    /**
     * In-memory cache TTL for setting reads. 60 seconds is short enough
     * that admin changes propagate quickly but long enough to save the
     * 99% case of repeated reads within one request batch.
     *
     * Note: this caches in Laravel's default cache store (file in dev,
     * Redis when wired up). When we move to multi-instance, all instances
     * share the cache via Redis and admin changes propagate identically.
     */
    private const CACHE_TTL_SECONDS = 60;

    private static function cacheKey(string $key): string
    {
        return "settings:{$key}";
    }

    /**
     * Read a setting value. Returns the default if the key is missing.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        return Cache::remember(
            self::cacheKey($key),
            self::CACHE_TTL_SECONDS,
            fn () => static::query()->where('key', $key)->value('value')
        ) ?? $default;
    }

    /**
     * Write a setting and invalidate the cache. Used by the admin
     * /admin/subscription/toggle endpoint (P9).
     */
    public static function set(string $key, string $value): void
    {
        static::query()->updateOrInsert(['key' => $key], ['value' => $value]);
        Cache::forget(self::cacheKey($key));
    }

    /**
     * Invalidate the cache for a key without writing. Use after raw-SQL
     * updates (admin lockout counters) so the in-memory cache doesn't
     * serve a stale value.
     */
    public static function forget(string $key): void
    {
        Cache::forget(self::cacheKey($key));
    }

    // ────────────────────────────────────────────────────────────────────────
    // Strongly-typed accessors for the well-known settings
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Is the paywall enforced? When false, all users get Pro access.
     *
     * Read by Device::effectiveTier() on every request that goes through
     * tier resolution — must be fast. The Cache::remember above keeps it
     * to one DB query per minute under steady load.
     */
    public static function subscriptionsEnabled(): bool
    {
        return static::get('subscriptions_enabled', 'true') === 'true';
    }

    /**
     * Toggle the paywall. Returns the new value. Called from
     * /admin/subscription/toggle (P9).
     */
    public static function toggleSubscriptions(): bool
    {
        $next = ! self::subscriptionsEnabled();
        self::set('subscriptions_enabled', $next ? 'true' : 'false');
        return $next;
    }

    /**
     * The disposable-email blocklist as an array of lowercased domains.
     *
     * Read on every trial activation attempt (C4 fix from Batch 2). The
     * cache layer keeps this cheap.
     *
     * @return array<int, string>
     */
    public static function disposableEmailDomains(): array
    {
        $csv = static::get('disposable_email_domains', '');
        if ($csv === null || $csv === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $csv))));
    }
}
