<?php

declare(strict_types=1);

use FancyMlm\Contracts\RewardSink;
use FancyMlm\Laravel\Facades\Mlm;
use FancyMlm\Laravel\Models\Member as MemberModel;
use FancyMlm\Referral\ReferralEngine;
use FancyMlm\Referral\RewardComputation;
use FancyMlm\Tests\Support\RecordingRewardSink;

it('creates the members table and reads the sponsor tree from Eloquent', function () {
    $sponsor = MemberModel::create(['tier' => 'gold', 'active' => true]);
    $member = MemberModel::create(['tier' => 'default', 'sponsor_id' => $sponsor->id]);

    expect(MemberModel::query()->count())->toBe(2);
    expect($member->sponsor->is($sponsor))->toBeTrue();
});

it('distributes a tier-scaled reward up the Eloquent tree via the container', function () {
    // origin -> s1(gold) -> s2(silver) -> s3(default) -> s4(beyond depth)
    $s4 = MemberModel::create(['tier' => 'gold']);
    $s3 = MemberModel::create(['tier' => 'default', 'sponsor_id' => $s4->id]);
    $s2 = MemberModel::create(['tier' => 'silver', 'sponsor_id' => $s3->id]);
    $s1 = MemberModel::create(['tier' => 'gold', 'sponsor_id' => $s2->id]);
    $origin = MemberModel::create(['tier' => 'default', 'sponsor_id' => $s1->id]);

    // capture what the engine pays through a recording sink (set before first resolve)
    $sink = new RecordingRewardSink();
    app()->instance(RewardSink::class, $sink);
    app()->forgetInstance(ReferralEngine::class);
    app()->forgetInstance('mlm');

    $rewards = Mlm::distribute((string) $origin->id, 100.0);

    expect($rewards)->toHaveCount(3);
    expect(array_map(fn (RewardComputation $r) => $r->recipientMemberId, $rewards))
        ->toBe([(string) $s1->id, (string) $s2->id, (string) $s3->id]);
    expect($rewards[0]->amount)->toBe(150.0); // gold, level 1
    expect($rewards[1]->amount)->toBe(62.5);  // silver, level 2
    expect($rewards[2]->amount)->toBe(25.0);  // default, level 3
    expect($sink->paid)->toHaveCount(3);
});

it('distributes from the member belonging to a user id', function () {
    $sponsor = MemberModel::create(['tier' => 'gold', 'user_id' => 10]);
    MemberModel::create(['tier' => 'default', 'user_id' => 20, 'sponsor_id' => $sponsor->id]);

    $rewards = Mlm::distributeForUser('20', 100.0);

    expect($rewards)->toHaveCount(1);
    expect($rewards[0]->recipientMemberId)->toBe((string) $sponsor->id);
    expect($rewards[0]->amount)->toBe(150.0);
});
