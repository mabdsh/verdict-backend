<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Default settings seeder
|--------------------------------------------------------------------------
|
| Seeds two rows the application expects to exist:
|
|   subscriptions_enabled
|     Global kill-switch for the paywall. 'true' means free-tier limits are
|     enforced; 'false' means every user gets Pro access (used during outages
|     or when toggling the paywall off for promotions). Toggle from
|     /admin/subscription/toggle (P9).
|
|   disposable_email_domains
|     Comma-separated lowercased domains blocked from trial activation
|     (C4 from Batch 2). Operator can update the list from /admin without
|     redeploying — TrialController reads it on every activation attempt.
|     Seed list is intentionally small (~16 domains); broad disposable-email
|     defence is a Batch 7+ problem.
|
| INSERT OR IGNORE on the seeder — running it twice is harmless. This is
| critical because the cutover path (existing DB → Laravel) may already have
| these rows from the Node backend's seedDefaults() and we don't want to
| overwrite operator changes.
*/

class DefaultSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedSubscriptionsEnabled();
        $this->seedDisposableEmailDomains();
    }

    private function seedSubscriptionsEnabled(): void
    {
        // INSERT OR IGNORE in SQLite — preserves any existing value
        DB::statement(
            "INSERT OR IGNORE INTO settings (key, value) VALUES ('subscriptions_enabled', 'true')"
        );
    }

    private function seedDisposableEmailDomains(): void
    {
        // Identical seed list to the Node backend's database.ts
        $domains = [
            'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', 'sharklasers.com',
            'tempmail.com', 'temp-mail.org', 'temp-mail.io', 'getnada.com', 'nada.email',
            '10minutemail.com', '10minutemail.net', 'yopmail.com', 'trashmail.com',
            'dispostable.com', 'maildrop.cc', 'fakeinbox.com', 'mailnesia.com',
        ];

        DB::statement(
            "INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)",
            ['disposable_email_domains', implode(',', $domains)]
        );
    }
}
