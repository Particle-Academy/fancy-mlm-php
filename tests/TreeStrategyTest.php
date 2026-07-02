<?php

declare(strict_types=1);

use FancyMlm\Member;
use FancyMlm\Plan\CompensationPlan;
use FancyMlm\Referral\ReferralEngine;
use FancyMlm\Tests\Support\ArrayMemberRepository;
use FancyMlm\Tests\Support\RecordingRewardSink;
use FancyMlm\Tree\TreeFactory;

/** @param array<string,mixed> $overrides */
function treePlan(string $tree, array $overrides = []): CompensationPlan
{
    return CompensationPlan::fromArray(array_merge([
        'metric' => 'referral-bonus',
        'levelFactors' => [1.0, 0.5],
        'tiers' => ['default' => 1.0],
        'tree' => $tree,
    ], $overrides));
}

/** @param list<\FancyMlm\Referral\RewardComputation> $rewards @return list<string> */
function names(array $rewards): array
{
    return array_map(static fn ($r) => $r->recipientMemberId, $rewards);
}

it('climbs the sponsor tree for unilevel and the placement tree for binary/matrix', function () {
    // origin was SPONSORED by S but PLACED under P (spillover) — the two trees diverge.
    $repo = new ArrayMemberRepository(
        new Member('origin', sponsorId: 'S', placementId: 'P'),
        new Member('S', sponsorId: 'S2'),
        new Member('S2', sponsorId: null),
        new Member('P', sponsorId: 'P2', placementId: 'P2'),
        new Member('P2', sponsorId: null, placementId: null),
    );

    $uni = (new ReferralEngine(treePlan('unilevel'), $repo, new RecordingRewardSink()))->distribute('origin', 100.0);
    expect(names($uni))->toBe(['S', 'S2']); // sponsor chain

    $bin = (new ReferralEngine(treePlan('binary'), $repo, new RecordingRewardSink()))->distribute('origin', 100.0);
    expect(names($bin))->toBe(['P', 'P2']); // placement chain
    expect($bin[0]->amount)->toBe(100.0);
    expect($bin[1]->amount)->toBe(50.0);

    $mat = (new ReferralEngine(treePlan('matrix', ['width' => 3]), $repo, new RecordingRewardSink()))->distribute('origin', 100.0);
    expect(names($mat))->toBe(['P', 'P2']); // placement chain
});

it('falls back to the sponsor pointer when a placement is not set (binary/matrix)', function () {
    $repo = new ArrayMemberRepository(
        new Member('origin', sponsorId: 'S'), // no placementId
        new Member('S', sponsorId: null),
    );

    $bin = (new ReferralEngine(treePlan('binary'), $repo, new RecordingRewardSink()))->distribute('origin', 100.0);
    expect(names($bin))->toBe(['S']);
});

it('exposes the frontline cap per tree type', function () {
    expect(TreeFactory::for(treePlan('unilevel'))->frontlineCap(treePlan('unilevel')))->toBe(0);
    expect(TreeFactory::for(treePlan('binary'))->frontlineCap(treePlan('binary')))->toBe(2);
    expect(TreeFactory::for(treePlan('matrix', ['width' => 4]))->frontlineCap(treePlan('matrix', ['width' => 4])))->toBe(4);
    expect(TreeFactory::for(treePlan('matrix'))->frontlineCap(treePlan('matrix')))->toBe(3); // default width
});

it('round-trips the tree type + width through plan JSON', function () {
    $plan = CompensationPlan::fromArray(['tree' => 'matrix', 'width' => 5, 'levelFactors' => [1.0]]);
    expect($plan->tree)->toBe('matrix');
    expect($plan->width)->toBe(5);
    expect($plan->toArray()['tree'])->toBe('matrix');
    expect($plan->toArray()['width'])->toBe(5);
});
