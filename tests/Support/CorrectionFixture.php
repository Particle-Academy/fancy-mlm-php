<?php

declare(strict_types=1);

namespace FancyMlm\Tests\Support;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use FancyMlm\Attribution\ArrayAttributionStore;
use FancyMlm\Attribution\AttributionPolicy;
use FancyMlm\Attribution\Exceptions\AttributionException;
use FancyMlm\Attribution\Exceptions\InvalidInput;
use FancyMlm\Attribution\ReferralCodes;
use FancyMlm\Attribution\ReferralProgram;
use FancyMlm\Attribution\SessionHasher;
use FancyMlm\Contracts\AttributionStore;
use FancyMlm\Contracts\SponsorCorrectionStore;

/**
 * Runner for the shared `mlm/sponsor-correction` table. The JS twin carries the
 * same file byte-for-byte and runs the same rows.
 */
final class CorrectionFixture
{
    /** SHA-256 of the fixture with CRLF folded to LF. Must match the JS twin's constant. */
    public const SHA256 = '570358bbf1f390f247cde2bcc8366be46fe03c803e5bd4959f00ee2eb7ab8301';

    public const AT = '2026-11-04T12:00:00Z';

    public static function path(): string
    {
        return __DIR__.'/../fixtures/sponsor-correction.json';
    }

    /** @return array<string,mixed> */
    public static function load(): array
    {
        return json_decode((string) file_get_contents(self::path()), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function digest(): string
    {
        return hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents(self::path())));
    }

    /** A literal string, or {repeat, times, then}. */
    public static function str(mixed $value): string
    {
        return is_array($value) ? str_repeat($value['repeat'], $value['times']).$value['then'] : (string) $value;
    }

    public static function authorizer(string $mode): ?Closure
    {
        return match ($mode) {
            'absent' => null,
            'allow' => static fn (): bool => true,
            'deny' => static fn (): bool => false,
            'truthy' => static fn (): int => 1,
        };
    }

    /** The expected block with every {repeat} expanded. @return array<string,mixed> */
    public static function expected(array $case): array
    {
        $expected = $case['expected'];
        foreach ($expected['audit'] as $i => $row) {
            $expected['audit'][$i]['reason'] = self::str($row['reason']);
            $expected['audit'][$i]['actorId'] = self::str($row['actorId']);
        }

        return $expected;
    }

    /**
     * Run one row against the in-memory store.
     *
     * @param array<string,mixed> $case
     * @return array<string,mixed>
     */
    public static function run(array $case): array
    {
        $store = new ArrayAttributionStore();
        foreach ($case['input']['members'] as $id => $sponsor) {
            $store->addMember((string) $id, $sponsor);
        }

        return self::runOn($case, $store, static fn (string $id): string => $id, static fn (?string $id): ?string => $id);
    }

    /**
     * Run one row against any store that has already been seeded with the
     * row's members, translating fixture ids to the store's ids and back.
     *
     * @param array<string,mixed> $case
     * @param Closure(string):string $toStore
     * @param Closure(?string):?string $fromStore
     * @return array<string,mixed>
     */
    public static function runOn(array $case, AttributionStore&SponsorCorrectionStore $store, Closure $toStore, Closure $fromStore): array
    {
        $in = $case['input'];
        $program = new ReferralProgram(
            $store,
            new SessionHasher('fixture-key'),
            new AttributionPolicy(),
            static fn (): DateTimeImmutable => new DateTimeImmutable(self::AT, new DateTimeZone('UTC')),
            ReferralCodes::DEFAULT_LENGTH,
            self::authorizer($in['authorize']),
        );

        $outcomes = [];
        foreach ($in['steps'] as $step) {
            try {
                $program->correctSponsor(
                    $toStore((string) $step['memberId']),
                    $toStore((string) $step['toSponsorId']),
                    self::str($step['reason']),
                    self::str($step['actorId']),
                );
                $outcomes[] = 'corrected';
            } catch (InvalidInput $e) {
                $outcomes[] = 'refused:'.$e->field;
            } catch (AttributionException $e) {
                $outcomes[] = (new \ReflectionClass($e))->getShortName();
            }
        }

        $sponsors = [];
        $audit = [];
        foreach (array_keys($in['members']) as $id) {
            $id = (string) $id;
            $sponsors[$id] = $fromStore($store->sponsorOf($toStore($id)));
            foreach ($store->sponsorCorrections($toStore($id)) as $c) {
                $audit[] = [
                    'memberId' => $fromStore($c->memberId),
                    'fromSponsorId' => $fromStore($c->fromSponsorId),
                    'toSponsorId' => $fromStore($c->toSponsorId),
                    'reason' => $c->reason,
                    'actorId' => $c->actorId,
                ];
            }
        }

        return ['outcomes' => $outcomes, 'sponsors' => $sponsors, 'audit' => $audit];
    }
}
