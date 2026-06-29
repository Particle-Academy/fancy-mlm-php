# Fancy MLM (PHP)

`particle-academy/fancy-mlm` — a framework-agnostic multi-level **referral /
network-marketing engine** for agentic apps. A pure-PHP core (zero framework in
`require`) with an optional **Laravel bridge** and first-class integration with
the Fancy stack: [fun-lab](https://github.com/Particle-Academy/laravel-fun-lab)
(gamified rewards), [catalog](https://github.com/Particle-Academy/laravel-catalog)
(commerce), and [fms](https://github.com/Particle-Academy/laravel-feature-management-system)
(tiers + metered bonuses).

> **MVP (v0.x):** Unilevel referral bonuses — a reward flows up the sponsor tree,
> decaying per level and scaling by each upline member's tier, with dynamic
> compression. The gamified fun-lab loop is wired. Binary/matrix trees, monetary
> commission ledgers, catalog/fms bridges, and the Node mirror + React UI are on
> the roadmap (see [`.ai/plans/fancy-mlm.md`](https://github.com/Particle-Academy/fancy.agi)).

## Install

```bash
composer require particle-academy/fancy-mlm
```

The **core requires zero framework**. The Laravel bridge auto-registers via
package discovery when you're on Laravel 11–13.

## Plain PHP (framework-agnostic core)

Implement two ports — read your members, receive computed rewards — and run the
engine:

```php
use FancyMlm\Plan\CompensationPlan;
use FancyMlm\Referral\ReferralEngine;

$plan = CompensationPlan::fromArray([
    'metric' => 'referral-bonus',
    'levelFactors' => [1.0, 0.5, 0.25],          // L1 100%, L2 50%, L3 25%
    'tiers' => ['default' => 1.0, 'silver' => 1.25, 'gold' => 1.5],
    'compression' => true,
]);

$engine = new ReferralEngine($plan, $yourMemberRepository, $yourRewardSink);

// A referred member just did something worth 100 — credit their upline:
$rewards = $engine->distribute(originMemberId: 'm-42', baseAmount: 100.0);
// gold sponsor at L1 earns 150; silver at L2 earns 62.5; default at L3 earns 25.
```

`MemberRepository` (`find(id)`) and `RewardSink` (`pay(RewardComputation)`) are
the only things you implement. `CompensationPlan` is JSON — the same shape the
forthcoming Node mirror loads, so both produce identical rewards.

## Laravel

The bridge ships an Eloquent member tree, a config-driven plan, a facade, and the
**gamified referral loop** (when `laravel-fun-lab` is installed):

```bash
php artisan vendor:publish --tag=mlm-config      # config/mlm.php (the plan + tiers)
php artisan vendor:publish --tag=mlm-migrations  # mlm_members table  (or rely on auto-load)
php artisan migrate
```

```php
use FancyMlm\Laravel\Facades\Mlm;

// Distribute from a member, or from the member belonging to a user:
$rewards = Mlm::distribute($memberId, 100.0);
$rewards = Mlm::distributeForUser($userId, 100.0);
```

**The fun-lab loop.** With `laravel-fun-lab` installed and `mlm.fun_lab.enabled`,
the bridge listens for `XpAwarded`: when a referral earns XP, their upline is
credited in points — scaled by level and tier — via
`LFL::award(...)->from('mlm')`. Awards stamped with that `source` are ignored by
the listener, so a bonus never re-triggers the loop (recursion guard).

Tiers map naturally onto **fms feature groups**, and a tier's scaling cap onto an
**fms resource-feature limit** (MAX-wins group overrides) — see the design doc.

## Data model

`mlm_members` carries two parent pointers so one schema serves every tree type:
`sponsor_id` (the enroller tree — who referred you, drives referral/matching) and
`placement_id` (binary/matrix placement, for future tree types). Unilevel uses
only the sponsor tree.

## Compliance

A legitimate MLM pays on **real product sales**, not recruitment. This engine is
built to anchor volume to actual catalog sales and to support clawbacks and
income-disclosure reporting as those layers land. Use it accordingly.

## Testing

```bash
composer install
composer test
```

## License

MIT.
