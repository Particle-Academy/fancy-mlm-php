<?php

declare(strict_types=1);

use FancyMlm\Attribution\ArrayAttributionStore;
use FancyMlm\Attribution\AttributionPolicy;
use FancyMlm\Attribution\Exceptions\CorrectionUnauthorized;
use FancyMlm\Attribution\Exceptions\InvalidInput;
use FancyMlm\Attribution\Exceptions\SponsorImmutable;
use FancyMlm\Attribution\ReferralCodes;
use FancyMlm\Attribution\ReferralProgram;
use FancyMlm\Attribution\SessionHasher;
use FancyMlm\Attribution\SponsorCorrection;
use FancyMlm\Attribution\SponsorCorrectionRequest;
use FancyMlm\Attribution\SponsorRules;
use FancyMlm\Contracts\AttributionStore;
use FancyMlm\Tests\Support\CorrectionFixture;

/*
 * The audited admin override: the ONE way to change a sponsor that is already
 * set. Every other path stays exactly as locked as 0.4.0.
 */

function correctingProgram(ArrayAttributionStore|AttributionStore $store, ?Closure $authorize, ?Closure $clock = null): ReferralProgram
{
    return new ReferralProgram(
        $store,
        new SessionHasher('test-key'),
        new AttributionPolicy(),
        $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-04T12:00:00Z'),
        ReferralCodes::DEFAULT_LENGTH,
        $authorize,
    );
}

function sponsoredStore(): ArrayAttributionStore
{
    return (new ArrayAttributionStore())->addMember('a')->addMember('b')->addMember('m', 'a');
}

describe('shared fixture: mlm/sponsor-correction', function () {
    it('carries the fixture byte-identical to the JS twin', function () {
        expect(CorrectionFixture::digest())->toBe(CorrectionFixture::SHA256);
    });

    it('passes row', function (array $case) {
        expect(CorrectionFixture::run($case))->toBe(CorrectionFixture::expected($case));
    })->with(function (): array {
        $rows = [];
        foreach (CorrectionFixture::load()['cases'] as $case) {
            $rows[$case['id']] = [$case];
        }

        return $rows;
    });
});

describe('correctSponsor', function () {
    it('returns the audit row it wrote, stamped by the clock', function () {
        $store = sponsoredStore();

        $c = correctingProgram($store, fn () => true)->correctSponsor('m', 'b', 'ticket 4411', 'admin-7');

        expect($c)->toBeInstanceOf(SponsorCorrection::class);
        expect($c->id)->not->toBeNull();
        expect([$c->memberId, $c->fromSponsorId, $c->toSponsorId, $c->reason, $c->actorId])
            ->toBe(['m', 'a', 'b', 'ticket 4411', 'admin-7']);
        expect($c->correctedAt->format(DATE_ATOM))->toBe('2026-11-04T12:00:00+00:00');
        expect($store->sponsorOf('m'))->toBe('b');
        expect($store->sponsorCorrections('m'))->toHaveCount(1);
    });

    it('hands the authorizer exactly what is being asked for', function () {
        $seen = null;
        correctingProgram(sponsoredStore(), function (SponsorCorrectionRequest $r) use (&$seen) {
            $seen = $r;

            return true;
        })->correctSponsor('m', 'b', '  ticket 4411 ', 'admin-7');

        expect([$seen->memberId, $seen->toSponsorId, $seen->reason, $seen->actorId])
            ->toBe(['m', 'b', 'ticket 4411', 'admin-7']);
    });

    it('refuses by default: no authorizer means no correction', function () {
        $store = sponsoredStore();

        expect(fn () => correctingProgram($store, null)->correctSponsor('m', 'b', 'ticket 1', 'admin-7'))
            ->toThrow(CorrectionUnauthorized::class);
        expect($store->sponsorOf('m'))->toBe('a');
        expect($store->sponsorCorrections('m'))->toBe([]);
    });

    it('lets an authorizer that throws abort the correction', function () {
        $store = sponsoredStore();

        expect(fn () => correctingProgram($store, fn () => throw new RuntimeException('policy down'))
            ->correctSponsor('m', 'b', 'ticket 1', 'admin-7'))->toThrow(RuntimeException::class, 'policy down');
        expect($store->sponsorOf('m'))->toBe('a');
        expect($store->sponsorCorrections('m'))->toBe([]);
    });

    it('refuses a reason or actor that is not valid UTF-8', function (string $reason, string $actor, string $field) {
        try {
            correctingProgram(sponsoredStore(), fn () => true)->correctSponsor('m', 'b', $reason, $actor);
            $this->fail('expected a refusal');
        } catch (InvalidInput $e) {
            expect($e->field)->toBe($field);
        }
    })->with([
        'reason' => ["bad \xC3\x28 bytes", 'admin-7', 'reason'],
        'actor' => ['ticket 1', "admin-\xFF", 'actorId'],
    ]);

    it('is refused with a clear error when the store cannot record corrections', function () {
        $inner = sponsoredStore();
        $store = new class($inner) implements AttributionStore {
            public function __construct(private ArrayAttributionStore $s) {}
            public function memberExists(string $memberId): bool { return $this->s->memberExists($memberId); }
            public function sponsorOf(string $memberId): ?string { return $this->s->sponsorOf($memberId); }
            public function assignSponsor(string $memberId, string $sponsorId): void { $this->s->assignSponsor($memberId, $sponsorId); }
            public function findCode(string $code): ?FancyMlm\Attribution\ReferralCode { return $this->s->findCode($code); }
            public function saveCode(FancyMlm\Attribution\ReferralCode $code): FancyMlm\Attribution\ReferralCode { return $this->s->saveCode($code); }
            public function revokeCode(string $code, DateTimeImmutable $at): bool { return $this->s->revokeCode($code, $at); }
            public function latestScan(string $code, string $sessionHash): ?FancyMlm\Attribution\Scan { return $this->s->latestScan($code, $sessionHash); }
            public function saveScan(FancyMlm\Attribution\Scan $scan): FancyMlm\Attribution\Scan { return $this->s->saveScan($scan); }
            public function candidatesForSession(string $sessionHash, DateTimeImmutable $from, DateTimeImmutable $to): array { return $this->s->candidatesForSession($sessionHash, $from, $to); }
            public function attributionFor(string $memberId): ?FancyMlm\Attribution\Attribution { return $this->s->attributionFor($memberId); }
            public function saveAttributionRecord(FancyMlm\Attribution\Attribution $attribution): void { $this->s->saveAttributionRecord($attribution); }
            public function transaction(callable $fn): mixed { return $this->s->transaction($fn); }
            public function stats(string $groupBy, DateTimeImmutable $from, DateTimeImmutable $to, array $filter = []): array { return $this->s->stats($groupBy, $from, $to, $filter); }
            public function pruneScans(DateTimeImmutable $before): int { return $this->s->pruneScans($before); }
        };

        expect(fn () => correctingProgram($store, fn () => true)->correctSponsor('m', 'b', 'ticket 1', 'admin-7'))
            ->toThrow(LogicException::class, 'SponsorCorrectionStore');
        expect($inner->sponsorOf('m'))->toBe('a');
    });
});

describe('every normal path stays locked', function () {
    it('still refuses assignSponsor on a set sponsor, before and after a correction', function () {
        $store = sponsoredStore();
        $program = correctingProgram($store, fn () => true);

        expect(fn () => $program->assignSponsor('m', 'b'))->toThrow(SponsorImmutable::class);
        $program->correctSponsor('m', 'b', 'ticket 1', 'admin-7');
        expect(fn () => $program->assignSponsor('m', 'a'))->toThrow(SponsorImmutable::class);
        expect($store->sponsorOf('m'))->toBe('b');
    });

    it('still refuses a change in the sponsor rules', function () {
        SponsorRules::assertAssignable('m', 'b', 'a', fn () => null);
    })->throws(SponsorImmutable::class);

    it('still refuses a change in the store itself', function () {
        $store = sponsoredStore();

        expect(fn () => $store->assignSponsor('m', 'b'))->toThrow(SponsorImmutable::class);
        expect($store->sponsorOf('m'))->toBe('a');
    });
});

describe('the in-memory store', function () {
    it('applies a correction only while the sponsor is still the one it corrects, writing nothing otherwise', function () {
        $store = sponsoredStore();
        $stale = new SponsorCorrection(null, 'm', 'b', 'a', 'stale', 'admin-7', new DateTimeImmutable());

        expect(fn () => $store->applySponsorCorrection($stale))->toThrow(SponsorImmutable::class);
        expect($store->sponsorOf('m'))->toBe('a');
        expect($store->sponsorCorrections('m'))->toBe([]);
    });

    it('rolls corrections back with the transaction', function () {
        $store = sponsoredStore();

        expect(fn () => $store->transaction(function () use ($store) {
            $store->applySponsorCorrection(new SponsorCorrection(null, 'm', 'a', 'b', 'ticket 1', 'admin-7', new DateTimeImmutable()));
            throw new RuntimeException('boom');
        }))->toThrow(RuntimeException::class);
        expect($store->sponsorOf('m'))->toBe('a');
        expect($store->sponsorCorrections('m'))->toBe([]);
    });

    it('keeps history oldest first with distinct ids', function () {
        $store = sponsoredStore();
        $program = correctingProgram($store, fn () => true);
        $program->correctSponsor('m', 'b', 'one', 'admin-7');
        $program->correctSponsor('m', 'a', 'two', 'admin-7');

        $rows = $store->sponsorCorrections('m');
        expect(array_map(fn (SponsorCorrection $c) => $c->reason, $rows))->toBe(['one', 'two']);
        expect($rows[0]->id)->not->toBe($rows[1]->id);
    });
});
