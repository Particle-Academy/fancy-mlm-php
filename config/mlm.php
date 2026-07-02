<?php

return [
    /*
    | The host's user model — fun-lab awards are made against the upline member's
    | related user (mlm_members.user_id -> this model).
    */
    'user_model' => env('MLM_USER_MODEL', 'App\\Models\\User'),

    'tables' => [
        'members' => 'mlm_members',
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
