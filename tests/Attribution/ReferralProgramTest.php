<?php

declare(strict_types=1);

use FancyMlm\Attribution\ArrayAttributionStore;
use FancyMlm\Attribution\AttributionPolicy;
use FancyMlm\Attribution\Exceptions\InvalidInput;
use FancyMlm\Attribution\Exceptions\SelfReferral;
use FancyMlm\Attribution\Exceptions\SponsorCycle;
use FancyMlm\Attribution\Exceptions\SponsorImmutable;
use FancyMlm\Attribution\Exceptions\UnknownMember;
use FancyMlm\Attribution\ReferralCodes;
use FancyMlm\Attribution\ReferralProgram;
use FancyMlm\Attribution\SessionHasher;
use FancyMlm\Attribution\SponsorRules;

function utc(string $iso): DateTimeImmutable
{
    return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
}

/** @param array<string,mixed> $policy */
function program(ArrayAttributionStore $store, array $policy = []): ReferralProgram
{
    return new ReferralProgram($store, new SessionHasher('test-key'), AttributionPolicy::fromArray($policy));
}

function storeWithMembers(string ...$ids): ArrayAttributionStore
{
    $store = new ArrayAttributionStore();
    foreach ($ids as $id) {
        $store->addMember($id);
    }

    return $store;
}

describe('codes', function () {
    it('generates 16 characters from the 32-letter alphabet by default (80 bits)', function () {
        $code = ReferralCodes::generate();

        expect(strlen($code))->toBe(16);
        expect(strspn($code, ReferralCodes::ALPHABET))->toBe(16);
        expect(ReferralCodes::normalize($code))->toBe($code);
    });

    it('does not repeat across many draws', function () {
        $seen = [];
        for ($i = 0; $i < 2000; $i++) {
            $seen[ReferralCodes::generate()] = true;
        }

        expect($seen)->toHaveCount(2000);
    });

    it('uses every letter of the alphabet, so no character is starved by a biased mapping', function () {
        $counts = count_chars(implode('', array_map(fn () => ReferralCodes::generate(32), range(1, 400))), 1);

        expect(count($counts))->toBe(32);
    });

    it('refuses a length below the floor', function () {
        ReferralCodes::generate(11);
    })->throws(InvalidInput::class);

    it('issues many codes for one member across scopes, each active and unexpired', function () {
        $store = storeWithMembers('1');
        $program = program($store);

        $a = $program->issueCode('1', 'scope-a');
        $b = $program->issueCode('1', ['team', 'north']);
        $c = $program->issueCode('1', 'scope-a', utc('2027-01-01T00:00:00Z'));

        expect([$a->code, $b->code, $c->code])->each->toHaveLength(16);
        expect(array_unique([$a->code, $b->code, $c->code]))->toHaveCount(3);
        expect($b->scope)->toBe('team:north');
        expect($c->expiresAt?->format(DATE_ATOM))->toBe('2027-01-01T00:00:00+00:00');
        expect($a->status)->toBe('active');
    });

    it('refuses to issue a code for an unknown member', function () {
        program(new ArrayAttributionStore())->issueCode('nobody', 'x');
    })->throws(UnknownMember::class);

    it('stops recording scans for a revoked code', function () {
        $store = storeWithMembers('1');
        $program = program($store);
        $code = $program->issueCode('1', 'x')->code;

        $program->revokeCode($code);

        expect($program->recordScan($code, 'sess', [], utc('2026-11-04T10:00:00Z'))->status)->toBe('code_inactive');
        expect($store->findCode($code)?->status)->toBe('revoked');
    });
});

describe('scans', function () {
    it('stores the keyed hash, never the session id, and only the context the host passed', function () {
        $store = storeWithMembers('1');
        $program = program($store);
        $code = $program->issueCode('1', 'x')->code;

        $result = $program->recordScan($code, 'raw-session-id', ['campaign' => 'spring'], utc('2026-11-04T10:00:00Z'));

        expect($result->status)->toBe('recorded');
        expect($result->scan->sessionHash)->toBe(hash_hmac('sha256', 'raw-session-id', 'test-key'));
        expect($result->scan->context)->toBe(['campaign' => 'spring']);
        expect(serialize($store))->not->toContain('raw-session-id');
    });

    it('refuses context that cannot be stored as JSON', function () {
        $store = storeWithMembers('1');
        $program = program($store);
        $code = $program->issueCode('1', 'x')->code;

        $program->recordScan($code, 's', ['bad' => NAN]);
    })->throws(InvalidInput::class);
});

describe('attribution', function () {
    it('sets the sponsor from the scan and records the attribution', function () {
        $store = storeWithMembers('owner', 'new');
        $program = program($store);
        $code = $program->issueCode('owner', 'x')->code;
        $program->recordScan($code, 'visitor', [], utc('2026-11-04T10:00:00Z'));

        $decision = $program->attributeSignup('new', 'visitor', utc('2026-11-04T11:00:00Z'));

        expect($decision->attributed)->toBeTrue();
        expect($decision->sponsorMemberId)->toBe('owner');
        expect($store->sponsorOf('new'))->toBe('owner');
        expect($store->attributionFor('new')?->code)->toBe($code);
    });

    it('defaults to first touch with a 30-day window', function () {
        $policy = AttributionPolicy::fromArray([]);

        expect($policy->model)->toBe('first_touch');
        expect($policy->windowSeconds)->toBe(30 * 86400);
        expect($policy->dedupeSeconds)->toBe(1800);
    });

    it('is idempotent: a second signup call returns the first attribution and changes nothing', function () {
        $store = storeWithMembers('a', 'b', 'new');
        $program = program($store);
        $codeA = $program->issueCode('a', 'x')->code;
        $codeB = $program->issueCode('b', 'x')->code;
        $program->recordScan($codeA, 'visitor', [], utc('2026-11-04T10:00:00Z'));
        $program->attributeSignup('new', 'visitor', utc('2026-11-04T11:00:00Z'));
        $program->recordScan($codeB, 'visitor', [], utc('2026-11-04T12:00:00Z'));

        $again = program($store, ['model' => 'last_touch'])->attributeSignup('new', 'visitor', utc('2026-11-04T13:00:00Z'));

        expect($again->reason)->toBe('already_attributed');
        expect($again->sponsorMemberId)->toBe('a');
        expect($store->sponsorOf('new'))->toBe('a');
    });

    it('does not attribute a member who already has a sponsor', function () {
        $store = storeWithMembers('a', 'b', 'new');
        $program = program($store);
        $program->assignSponsor('new', 'a');
        $code = $program->issueCode('b', 'x')->code;
        $program->recordScan($code, 'visitor', [], utc('2026-11-04T10:00:00Z'));

        $decision = $program->attributeSignup('new', 'visitor', utc('2026-11-04T11:00:00Z'));

        expect($decision->attributed)->toBeFalse();
        expect($decision->reason)->toBe('already_sponsored');
        expect($store->sponsorOf('new'))->toBe('a');
    });

    it('attributes nothing to an expired code', function () {
        $store = storeWithMembers('owner', 'new');
        $program = program($store);
        $code = $program->issueCode('owner', 'x', utc('2026-11-04T10:30:00Z'))->code;
        $program->recordScan($code, 'visitor', [], utc('2026-11-04T10:00:00Z'));

        $decision = $program->attributeSignup('new', 'visitor', utc('2026-11-04T11:00:00Z'));

        expect($decision->reason)->toBe('code_inactive');
        expect($store->sponsorOf('new'))->toBeNull();
    });

    it('refuses self-referral through a scan', function () {
        $store = storeWithMembers('owner');
        $program = program($store);
        $code = $program->issueCode('owner', 'x')->code;
        $program->recordScan($code, 'visitor', [], utc('2026-11-04T10:00:00Z'));

        $decision = $program->attributeSignup('owner', 'visitor', utc('2026-11-04T11:00:00Z'));

        expect($decision->reason)->toBe('self_referral');
        expect($store->sponsorOf('owner'))->toBeNull();
    });
});

describe('the permanent sponsor', function () {
    it('can be set once', function () {
        $store = storeWithMembers('a', 'm');
        program($store)->assignSponsor('m', 'a');

        expect($store->sponsorOf('m'))->toBe('a');
    });

    it('accepts the same sponsor again as a no-op', function () {
        $store = storeWithMembers('a', 'm');
        program($store)->assignSponsor('m', 'a');
        program($store)->assignSponsor('m', 'a');

        expect($store->sponsorOf('m'))->toBe('a');
    });

    it('fails loudly on any attempt to change it', function () {
        $store = storeWithMembers('a', 'b', 'm');
        program($store)->assignSponsor('m', 'a');
        program($store)->assignSponsor('m', 'b');
    })->throws(SponsorImmutable::class);

    it('fails loudly on a change made through the store directly', function () {
        $store = storeWithMembers('a', 'b', 'm');
        $store->assignSponsor('m', 'a');
        $store->assignSponsor('m', 'b');
    })->throws(SponsorImmutable::class);

    it('refuses self-referral', function () {
        $store = storeWithMembers('m');
        program($store)->assignSponsor('m', 'm');
    })->throws(SelfReferral::class);

    it('refuses a cycle', function () {
        $store = storeWithMembers('a', 'b', 'c');
        $program = program($store);
        $program->assignSponsor('b', 'a');
        $program->assignSponsor('c', 'b');
        $program->assignSponsor('a', 'c');
    })->throws(SponsorCycle::class);

    it('is refused by the rules themselves, before any store is asked', function () {
        SponsorRules::assertAssignable('m', 'b', 'a', fn (string $id): ?string => null);
    })->throws(SponsorImmutable::class);

    it('refuses an unknown sponsor', function () {
        $store = storeWithMembers('m');
        program($store)->assignSponsor('m', 'ghost');
    })->throws(UnknownMember::class);
});

describe('retention', function () {
    it('prunes scans older than the retention period', function () {
        $store = storeWithMembers('1');
        $program = program($store, ['windowSeconds' => 86400]);
        $code = $program->issueCode('1', 'x')->code;
        $program->recordScan($code, 'old', [], utc('2026-10-01T00:00:00Z'));
        $program->recordScan($code, 'new', [], utc('2026-11-03T00:00:00Z'));

        $deleted = $program->pruneScans(7 * 86400, utc('2026-11-04T00:00:00Z'));

        expect($deleted)->toBe(1);
        expect($store->scanCount())->toBe(1);
    });

    it('refuses a retention shorter than the attribution window, which would silently stop attribution', function () {
        program(new ArrayAttributionStore(), ['windowSeconds' => 30 * 86400])->pruneScans(7 * 86400);
    })->throws(InvalidInput::class);
});
