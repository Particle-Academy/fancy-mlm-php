<?php

declare(strict_types=1);

namespace FancyMlm\Plan;

/**
 * A rank / tier in a compensation plan. Its {@see $multiplier} scales the reward
 * a member at this tier earns — the "scaling bonus by tier" lever. (In a Laravel
 * host this typically mirrors an fms feature group + resource-limit override.)
 */
final class Tier
{
    public function __construct(
        public readonly string $key,
        public readonly float $multiplier = 1.0,
        public readonly ?string $label = null,
    ) {}
}
