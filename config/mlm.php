<?php

return [
    /*
    | The host's user model — fun-lab awards are made against the upline member's
    | related user (mlm_members.user_id -> this model).
    */
    'user_model' => env('MLM_USER_MODEL', 'App\\Models\\User'),

    'tables' => [
        'members' => 'mlm_members',
        'referral_codes' => 'mlm_referral_codes',
        'referral_scans' => 'mlm_referral_scans',
        'attributions' => 'mlm_attributions',
        // Append-only audit trail of Mlm::correctSponsor().
        'sponsor_corrections' => 'mlm_sponsor_corrections',
    ],

    /*
    | Referral codes, scan logging and scan-to-signup attribution.
    |
    | Privacy: a scan stores the code, a keyed HMAC of a session token, the time
    | and only the context YOU pass. No IP, no user agent, no raw session id.
    | If you put either in a scan's context yourself, the scans table becomes
    | personal data and `retention_days` becomes a legal obligation.
    */
    'attribution' => [
        // 'first_touch' (default: the first introduction wins and cannot be
        // sniped by a later link) or 'last_touch'.
        'model' => env('MLM_ATTRIBUTION_MODEL', 'first_touch'),

        // A scan attributes a signup made within this many days of it.
        'window_days' => (int) env('MLM_ATTRIBUTION_WINDOW_DAYS', 30),

        // Repeat scans of one code from one session inside this window are not
        // stored, so refreshes do not inflate counts.
        'dedupe_minutes' => (int) env('MLM_SCAN_DEDUPE_MINUTES', 30),

        // `php artisan mlm:prune-scans` deletes scans older than this. Must be
        // at least `window_days`: a pruned scan cannot attribute. null = keep.
        'retention_days' => env('MLM_SCAN_RETENTION_DAYS', 90),

        // Generated code length: 12 to 64 characters of a 32-letter alphabet
        // (5 bits each). 16 = 80 bits.
        'code_length' => 16,

        // HMAC key for session hashes. Empty = derived from APP_KEY. Rotating
        // either breaks dedupe and attribution for sessions already in flight.
        'session_key' => env('MLM_SESSION_HASH_KEY'),

        // Install a database trigger that refuses any change to a set
        // sponsor_id (SQLite, MySQL/MariaDB, PostgreSQL), except the one an
        // audit row from Mlm::correctSponsor() names, and triggers that make
        // that audit table append-only. Turn off only if your database user
        // cannot create triggers; the code guards still apply.
        'database_guard' => true,
    ],

    /*
    | The fun-lab `from()` tag stamped on MLM-originated awards. The XpAwarded
    | listener ignores awards carrying this source so a referral bonus never
    | re-triggers the referral loop (recursion guard).
    */
    'reward_source' => 'mlm',

    /*
    | The compensation plan (the same shape the Node mirror loads). A referral
    | bonus that decays per level and scales by the upline member's tier.
    |
    | `tree` selects the downline shape the reward climbs:
    |   - 'unilevel' — unlimited frontline, walks the SPONSOR (enroller) tree
    |   - 'binary'   — two legs per node, walks the PLACEMENT tree (frontline 2)
    |   - 'matrix'   — forced W×depth, walks the PLACEMENT tree (frontline = width)
    | `width` sizes the matrix frontline (ignored by unilevel/binary).
    */
    'plan' => [
        'tree' => 'unilevel',
        'width' => 0,
        'metric' => 'referral-bonus',
        'levelFactors' => [1.0, 0.5, 0.25],
        'tiers' => [
            'default' => 1.0,
            'silver' => 1.25,
            'gold' => 1.5,
        ],
        'compression' => true,
        'defaultTier' => 'default',
    ],

    /*
    | The gamified referral loop. When enabled and laravel-fun-lab is installed,
    | a referral earning XP credits their upline in points (scaled by level + tier).
    */
    'fun_lab' => [
        'enabled' => true,
        // Multiply the referral's earned XP to get the engine's base amount.
        'base_factor' => 1.0,
        // Only react to XP awarded for these metric slugs (empty = all, minus our own).
        'trigger_metrics' => [],
    ],
];
