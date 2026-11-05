<?php

declare(strict_types=1);

use FancyMlm\Tests\Support\ReferralFixture;

/*
 * The shared `mlm/referral-attribution` table. The JS twin runs the same file;
 * a decision that differs between the two shows up as a red row on one side.
 */

it('carries the fixture byte-identical to the JS twin', function () {
    expect(ReferralFixture::digest())->toBe(ReferralFixture::SHA256);
});

it('has rows for every function the twins must agree on', function () {
    $fns = array_unique(array_column(ReferralFixture::load()['cases'], 'fn'));
    sort($fns);

    expect($fns)->toBe(['decide', 'hashSession', 'normalizeCode', 'scanSequence', 'scopeKey', 'stats']);
});

it('passes row', function (array $case) {
    $actual = ReferralFixture::run($case);

    // JSON has one number type: `0` decodes as int while a rate is a float.
    // Rates compare by value; every other field compares strictly.
    $case['fn'] === 'stats'
        ? expect($actual)->toEqual($case['expected'])
        : expect($actual)->toBe($case['expected']);
})->with(function (): array {
    $rows = [];
    foreach (ReferralFixture::load()['cases'] as $case) {
        $rows[$case['id']] = [$case];
    }

    return $rows;
});
