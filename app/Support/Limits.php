<?php

declare(strict_types=1);

namespace App\Support;

/*
|--------------------------------------------------------------------------
| Verdict — Single source of truth for tier limits, pricing, and copy
|--------------------------------------------------------------------------
|
| Port of Node's src/config/limits.ts. Same numbers, same structure, same
| invariants. Anywhere you want to change a number or a customer-facing
| string, change it here.
|
| Imported by: middleware (rate-limit), services (subscription status),
| controllers (responses to /api/subscription/status).
|
| The /api/subscription/status endpoint serialises every value below into the
| extension popup so the UI never hardcodes prices or limit numbers either.
|
| All values are exposed as class constants — no instance state, never
| mutated at runtime. Use Limits::LIMITS['free']['panel'], etc.
*/
final class Limits
{
    /*
    |--------------------------------------------------------------------------
    | Per-tier daily limits
    |--------------------------------------------------------------------------
    |
    | Each entry below maps directly to a UsageType. null = unlimited.
    |
    | Score is an anti-abuse ceiling on the daily VOLUME of jobs scored (not
    | API call count — one /api/score/batch call increments by jobs.length).
    | The numbers are sized to be effectively unlimited for normal use:
    |   - LinkedIn shows ~25 cards/page, so free=500 is 20 pages of unique jobs.
    |   - With the extension's 24h client-side score cache, repeat views don't
    |     re-score, so 500 represents 500 unique jobs viewed in a day — already
    |     2–3× what aggressive job seekers hit in practice.
    | Pro stays null (truly unlimited per the marketing promise); Pro abuse
    | will be bounded by edge rate limiting (per-IP), not per-device caps.
    */
    public const LIMITS = [
    'free'  => ['panel' => 3,    'analyze' => 3,    'profile' => null, 'score' => 500],
    'trial' => ['panel' => 10,   'analyze' => 10,   'profile' => null, 'score' => 1500],
    'pro'   => ['panel' => null, 'analyze' => null, 'profile' => null, 'score' => null],
];

    /*
    |--------------------------------------------------------------------------
    | Panel-open limits (used by SubscriptionController + PanelController)
    |--------------------------------------------------------------------------
    */
    public const PANEL_LIMITS = [
        'free'  => 3,
        'trial' => 10,
        'pro'   => null,
    ];

    /*
    |--------------------------------------------------------------------------
    | Rate-limited API call limits (analyze, profile)
    |--------------------------------------------------------------------------
    |
    | Score is intentionally not in this object — score has volume-based
    | semantics (consume by jobs.length, not 1) and is enforced via
    | CheckScoreVolumeLimit middleware (P4), which reads from SCORE_LIMITS.
    */
    public const CALL_LIMITS = [
    'analyze' => ['free' => 3,    'trial' => 10,   'pro' => null],
    'profile' => ['free' => null, 'trial' => null, 'pro' => null],
];

    /*
    |--------------------------------------------------------------------------
    | Score volume limits
    |--------------------------------------------------------------------------
    */
    public const SCORE_LIMITS = [
        'free'  => 500,
        'trial' => 1500,
        'pro'   => null,
    ];

    /*
    |--------------------------------------------------------------------------
    | Max jobs accepted in a single /api/score/batch request
    |--------------------------------------------------------------------------
    |
    | Request-shape cap, not a rate limit. LinkedIn search shows ~25 cards
    | per page; 50 gives generous headroom while preventing pathological
    | 500-job requests that would blow up the Groq prompt size.
    */
    public const MAX_JOBS_PER_BATCH = 50;

    /*
    |--------------------------------------------------------------------------
    | Trial duration
    |--------------------------------------------------------------------------
    */
    public const TRIAL_DAYS = 7;

    /*
    |--------------------------------------------------------------------------
    | Past-due grace period
    |--------------------------------------------------------------------------
    |
    | past_due = LemonSqueezy is retrying a failed payment. Customers in this
    | state keep Pro access for PAST_DUE_GRACE_DAYS days from when the failure
    | first occurred. LemonSqueezy retries for ~16 days before giving up;
    | 7 days protects revenue without being overly punitive.
    */
    public const PAST_DUE_GRACE_DAYS = 7;

    /*
    |--------------------------------------------------------------------------
    | Log retention (days)
    |--------------------------------------------------------------------------
    |
    | The daily CleanupLogsCommand (P10) prunes any row in:
    |   request_logs / panel_opens / usage / webhook_events / email_outbox
    | older than LOG_RETENTION_DAYS. Default 30 days — enough to investigate
    | a user's "this happened a week ago" report; not enough to grow the
    | SQLite file unboundedly.
    |
    | email_verifications has its own retention rule (7 days past expiry)
    | inside the command itself — codes expire after 10 minutes anyway, so
    | keeping a few extra days for audit is cheap.
    */
    public const LOG_RETENTION_DAYS = 30;

    /*
    |--------------------------------------------------------------------------
    | Pricing
    |--------------------------------------------------------------------------
    */
    public const PRICING = [
        'monthly_usd'           => 9,
        'yearly_usd'            => 84,
        'monthly_label'         => '$9/month',
        'yearly_label'          => '$84/year',
        'yearly_equivalent'     => '$7/month, billed annually',
        'yearly_savings_label'  => 'Save 22% · 2+ months free',
    ];

    /*
    |--------------------------------------------------------------------------
    | User-facing tier copy
    |--------------------------------------------------------------------------
    |
    | The popup reads this from /api/subscription/status — never hardcoded in
    | the extension. When you change a price, a feature, or the trial length,
    | this is the only place that needs an edit.
    |
    | Note that bullets reference LIMITS values directly; if you change the
    | numbers above, also update the bullet text below.
    */
    public const TIER_COPY = [
        'free' => [
            'name'     => 'Free',
            'headline' => 'Job scoring on every card you see',
            'bullets'  => [
                'Unlimited job card scores',
                '3 detailed panels per day',
                '3 AI coaching analyses per day',
                'Unlimited profile parses',
            ],
        ],
        'trial' => [
            'name'          => 'Free trial',
            'headline'      => '7 days of full Pro access — no card required',
            'duration_days' => 7,
            'bullets'       => [
                'Unlimited job card scores',
                '10 detailed panels per day',
                '10 AI coaching analyses per day',
                'Unlimited profile parses',
            ],
        ],
        'pro' => [
            'name'     => 'Pro',
            'headline' => 'Unlimited everything — for serious job seekers',
            // pricing key intentionally absent here — added at runtime in
            // SubscriptionController so this constant stays static-evaluable.
            'bullets' => [
                'Unlimited job card scores',
                'Unlimited detailed panels',
                'Unlimited AI coaching analyses',
                'Unlimited profile parses',
                'Priority support',
            ],
        ],
    ];

    /*
    |--------------------------------------------------------------------------
    | Valid tier names
    |--------------------------------------------------------------------------
    */
    public const TIERS = ['free', 'trial', 'pro'];

    /*
    |--------------------------------------------------------------------------
    | Usage types
    |--------------------------------------------------------------------------
    */
    public const USAGE_TYPES         = ['score', 'analyze', 'profile'];
    public const RATE_LIMITED_TYPES  = ['analyze', 'profile']; // not score

    /**
     * Convenience: get the TIER_COPY array enriched with the live PRICING
     * sub-array under the 'pro' key. Called by SubscriptionController.
     *
     * Why this exists instead of putting PRICING inside the TIER_COPY const:
     * PHP constants can only reference other constants if their values are
     * static-evaluable at compile time. The compiler accepts `self::PRICING`
     * inside another constant in PHP 8.3 but the resulting structure is
     * harder to inspect at runtime. Returning the array from a method keeps
     * everything legible.
     *
     * @return array<string, mixed>
     */
    public static function tierCopyWithPricing(): array
    {
        $copy = self::TIER_COPY;
        $copy['pro']['pricing'] = self::PRICING;
        return $copy;
    }
}
