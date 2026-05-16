<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;

/**
 * App\Support\EmailHelper
 *
 * Port of the email-helper functions from the Node backend's database.ts:
 *
 *   canonicalizeEmail()  — strip plus-tags and (for Gmail) dots, lowercase
 *   isDisposable()       — check against the settings-table blocklist
 *   mask()               — produce `f**@example.com` form for logging
 *
 * All three are pure functions but live as static methods on a class so they
 * share the same namespace and can be unit-tested as a unit. They're called
 * from multiple controllers (trial activation, subscription restore, device
 * email save, webhook logging) so centralisation matters.
 */
final class EmailHelper
{
    /**
     * Normalize an email to its canonical form so that aliases pointing at
     * the same inbox compare equal. Returns empty string on malformed input.
     *
     * Examples:
     *   foo+anything@example.com    → foo@example.com
     *   first.last+x@gmail.com      → firstlast@gmail.com
     *   First.Last@GoogleMail.com   → firstlast@googlemail.com
     *
     * Note: dot-stripping is Gmail-specific. Most other providers treat
     * dots as significant. We deliberately don't add Outlook/Yahoo dot
     * rules until we see actual abuse evidence — false positives
     * (treating distinct users as the same) are worse than false negatives.
     */
    public static function canonicalize(string $raw): string
    {
        $lowered = strtolower(trim($raw));
        $atIdx   = strpos($lowered, '@');

        // Need at least one char before @ and at least one after.
        if ($atIdx === false || $atIdx < 1 || $atIdx === strlen($lowered) - 1) {
            return '';
        }

        $local  = substr($lowered, 0, $atIdx);
        $domain = substr($lowered, $atIdx + 1);

        // Strip plus-tag.
        $plusIdx = strpos($local, '+');
        if ($plusIdx !== false) {
            $local = substr($local, 0, $plusIdx);
        }

        // Gmail-specific: dots in local part are ignored.
        if ($domain === 'gmail.com' || $domain === 'googlemail.com') {
            $local = str_replace('.', '', $local);
        }

        if ($local === '') {
            return '';
        }

        return $local . '@' . $domain;
    }

    /**
     * Check if the email's domain is in the disposable-email blocklist.
     *
     * The list lives in the settings table (key: disposable_email_domains)
     * and can be updated via /admin without redeploying. Setting model
     * caches the list with a 60-second TTL — calls here are cheap.
     */
    public static function isDisposable(string $email): bool
    {
        $atIdx = strpos($email, '@');
        if ($atIdx === false) {
            return false;
        }
        $domain = strtolower(trim(substr($email, $atIdx + 1)));
        if ($domain === '') {
            return false;
        }

        return in_array($domain, Setting::disposableEmailDomains(), true);
    }

    /**
     * Mask an email for logging: foo@example.com → f**@example.com.
     *
     * Used everywhere we'd otherwise log the raw email — keeps logs free
     * of PII while still being identifiable enough for debugging.
     */
    public static function mask(?string $raw): string
    {
        if ($raw === null || $raw === '') {
            return '<no-email>';
        }

        $atIdx = strpos($raw, '@');
        if ($atIdx === false || $atIdx < 1) {
            return '<malformed>';
        }

        $local   = substr($raw, 0, $atIdx);
        $domain  = substr($raw, $atIdx + 1);
        $visible = $local[0];
        $stars   = str_repeat('*', max(1, strlen($local) - 1));

        return $visible . $stars . '@' . $domain;
    }

    /**
     * Quick syntactic validity check. Same regex as the Node backend's
     * EMAIL_RE — not RFC-exhaustive but catches all realistic garbage.
     */
    public static function isValid(string $email): bool
    {
        if (strlen($email) > 254 || strlen($email) === 0) {
            return false;
        }
        return (bool) preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/', $email);
    }
}
