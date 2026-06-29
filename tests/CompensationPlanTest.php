<?php

declare(strict_types=1);

use FancyMlm\Plan\CompensationPlan;

it('parses tiers from scalar or object form', function () {
    $plan = CompensationPlan::fromArray([
        'metric' => 'pts',
        'levelFactors' => [1, 0.5],
        'tiers' => ['gold' => 1.5, 'silver' => ['multiplier' => 1.25, 'label' => 'Silver']],
        'compression' => false,
        'defaultTier' => 'base',
    ]);

    expect($plan->levels())->toBe(2);
    expect($plan->levelFactor(1))->toBe(1.0);
    expect($plan->levelFactor(2))->toBe(0.5);
    expect($plan->levelFactor(3))->toBe(0.0); // beyond depth
    expect($plan->tierMultiplier('gold'))->toBe(1.5);
    expect($plan->tierMultiplier('silver'))->toBe(1.25);
    expect($plan->compression)->toBeFalse();
});

it('falls back to 1.0 for an unknown tier with no default defined', function () {
    $plan = CompensationPlan::fromArray(['levelFactors' => [1.0], 'tiers' => ['gold' => 2.0]]);

    expect($plan->tierMultiplier('unknown'))->toBe(1.0);
});

it('uses the default tier multiplier for an unknown tier when defined', function () {
    $plan = CompensationPlan::fromArray([
        'levelFactors' => [1.0],
        'tiers' => ['gold' => 2.0, 'default' => 1.1],
    ]);

    expect($plan->tierMultiplier('unknown'))->toBe(1.1);
});

it('round-trips through toArray', function () {
    $data = [
        'metric' => 'referral-bonus',
        'levelFactors' => [1.0, 0.5],
        'tiers' => ['silver' => ['multiplier' => 1.25, 'label' => 'Silver']],
        'compression' => true,
        'defaultTier' => 'default',
    ];

    $out = CompensationPlan::fromArray($data)->toArray();

    expect($out['metric'])->toBe('referral-bonus');
    expect($out['levelFactors'])->toBe([1.0, 0.5]);
    expect($out['tiers']['silver'])->toBe(['multiplier' => 1.25, 'label' => 'Silver']);
    expect($out['compression'])->toBeTrue();
});
