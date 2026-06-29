<?php

declare(strict_types=1);

use FancyMlm\Member;
use FancyMlm\Plan\CompensationPlan;
use FancyMlm\Referral\ReferralEngine;
use FancyMlm\Tests\Support\ArrayMemberRepository;
use FancyMlm\Tests\Support\RecordingRewardSink;

/** @param array<string,mixed> $overrides */
function plan(array $overrides = []): CompensationPlan
{
    return CompensationPlan::fromArray(array_merge([
        'metric' => 'referral-bonus',
        'levelFactors' => [1.0, 0.5, 0.25],
        'tiers' => ['gold' => 1.5, 'silver' => 1.25, 'default' => 1.0],
        'compression' => true,
    ], $overrides));
}

/** @param list<\FancyMlm\Referral\RewardComputation> $rewards @return list<string> */
function recipients(array $rewards): array
{
    return array_map(static fn ($r) => $r->recipientMemberId, $rewards);
}

it('distributes a tier-scaled, level-decayed reward up the sponsor tree', function () {
    // origin(referral) -> s1(gold) -> s2(silver) -> s3(default) -> s4(beyond depth)
    $repo = new ArrayMemberRepository(
        new Member('origin', sponsorId: 's1'),
        new Member('s1', sponsorId: 's2', tier: 'gold'),
        new Member('s2', sponsorId: 's3', tier: 'silver'),
        new Member('s3', sponsorId: 's4', tier: 'default'),
        new Member('s4', sponsorId: null, tier: 'gold'),
    );
    $sink = new RecordingRewardSink();

    $rewards = (new ReferralEngine(plan(), $repo, $sink))->distribute('origin', 100.0);

    expect($rewards)->toHaveCount(3);
    expect($sink->paid)->toHaveCount(3);
    expect(recipients($rewards))->toBe(['s1', 's2', 's3']);

    [$l1, $l2, $l3] = $rewards;
    expect($l1->level)->toBe(1);
    expect($l1->amount)->toBe(150.0);  // 100 * 1.0 * 1.5
    expect($l2->amount)->toBe(62.5);   // 100 * 0.5 * 1.25
    expect($l3->amount)->toBe(25.0);   // 100 * 0.25 * 1.0
    expect($l1->amountAsInt())->toBe(150);
});

it('compresses past inactive uplines so the next active member earns the level', function () {
    // s2 is inactive -> skipped; s1=level1, s3=level2, s4=level3
    $repo = new ArrayMemberRepository(
        new Member('origin', sponsorId: 's1'),
        new Member('s1', sponsorId: 's2', tier: 'gold'),
        new Member('s2', sponsorId: 's3', tier: 'silver', active: false),
        new Member('s3', sponsorId: 's4', tier: 'default'),
        new Member('s4', sponsorId: null, tier: 'gold'),
    );
    $sink = new RecordingRewardSink();

    $rewards = (new ReferralEngine(plan(), $repo, $sink))->distribute('origin', 100.0);

    expect(recipients($rewards))->toBe(['s1', 's3', 's4']);
    expect($rewards[1]->level)->toBe(2);
    expect($rewards[1]->amount)->toBe(50.0);   // s3: 100 * 0.5 * 1.0
    expect($rewards[2]->amount)->toBe(37.5);   // s4: 100 * 0.25 * 1.5
});

it('stops at an inactive upline when compression is off', function () {
    $repo = new ArrayMemberRepository(
        new Member('origin', sponsorId: 's1'),
        new Member('s1', sponsorId: 's2', tier: 'gold'),
        new Member('s2', sponsorId: 's3', tier: 'silver', active: false),
        new Member('s3', sponsorId: null, tier: 'default'),
    );
    $sink = new RecordingRewardSink();

    $rewards = (new ReferralEngine(plan(['compression' => false]), $repo, $sink))->distribute('origin', 100.0);

    expect(recipients($rewards))->toBe(['s1']);
});

it('pays nothing for an unknown origin or a non-positive base amount', function () {
    $repo = new ArrayMemberRepository(new Member('origin', sponsorId: 's1'), new Member('s1', tier: 'gold'));
    $sink = new RecordingRewardSink();
    $engine = new ReferralEngine(plan(), $repo, $sink);

    expect($engine->distribute('ghost', 100.0))->toBe([]);
    expect($engine->distribute('origin', 0.0))->toBe([]);
    expect($engine->distribute('origin', -5.0))->toBe([]);
    expect($sink->paid)->toBe([]);
});

it('terminates on a cyclic sponsor chain', function () {
    // a -> b -> a (corrupt data) must not loop forever
    $repo = new ArrayMemberRepository(
        new Member('a', sponsorId: 'b', tier: 'default'),
        new Member('b', sponsorId: 'a', tier: 'default'),
    );
    $sink = new RecordingRewardSink();

    $rewards = (new ReferralEngine(plan(), $repo, $sink))->distribute('a', 100.0);

    expect(recipients($rewards))->toBe(['b']); // b earns level 1, then a (origin) is revisited -> stop
});

it('skips a zero-factor level without paying it', function () {
    $repo = new ArrayMemberRepository(
        new Member('origin', sponsorId: 's1'),
        new Member('s1', sponsorId: 's2', tier: 'default'),
        new Member('s2', sponsorId: null, tier: 'default'),
    );
    $sink = new RecordingRewardSink();

    // level 1 pays, level 2 factor is 0 -> no reward row for s2
    $rewards = (new ReferralEngine(plan(['levelFactors' => [1.0, 0.0]]), $repo, $sink))->distribute('origin', 100.0);

    expect(recipients($rewards))->toBe(['s1']);
});
