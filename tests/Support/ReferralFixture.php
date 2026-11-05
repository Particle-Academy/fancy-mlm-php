<?php

declare(strict_types=1);

namespace FancyMlm\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use FancyMlm\Attribution\Attribution;
use FancyMlm\Attribution\AttributionPolicy;
use FancyMlm\Attribution\Attributor;
use FancyMlm\Attribution\Exceptions\InvalidInput;
use FancyMlm\Attribution\ArrayAttributionStore;
use FancyMlm\Attribution\ReferralCode;
use FancyMlm\Attribution\ReferralCodes;
use FancyMlm\Attribution\ReferralProgram;
use FancyMlm\Attribution\Scan;
use FancyMlm\Attribution\Scope;
use FancyMlm\Attribution\SessionHasher;
use FancyMlm\Attribution\StatRow;
use FancyMlm\Contracts\AttributionStore;
use InvalidArgumentException;

/**
 * Runner for the shared `mlm/referral-attribution` table. The JS twin carries
 * the same file byte-for-byte and runs the same rows.
 */
final class ReferralFixture
{
    /** SHA-256 of the fixture with CRLF folded to LF. Must match the JS twin's constant. */
    public const SHA256 = '4a79b16adf4307a97628d0193c83535097778a1996ea9e4e1983d60664fb7750';

    public static function path(): string
    {
        return __DIR__.'/../fixtures/referral-attribution.json';
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

    public static function at(?string $iso): ?DateTimeImmutable
    {
        return $iso === null ? null : new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }

    /**
     * Run one row and return the value to compare against `expected`.
     *
     * @param array<string,mixed> $case
     * @param (callable(): AttributionStore)|null $storeFactory store for `stats` rows
     * @return array<string,mixed>
     */
    public static function run(array $case, ?callable $storeFactory = null): array
    {
        $in = $case['input'];

        return match ($case['fn']) {
            'hashSession' => self::refusable(fn () => ['hash' => (new SessionHasher($in['key']))->hash($in['sessionId'])]),
            'scopeKey' => self::refusable(fn () => ['key' => Scope::key($in['scope'])]),
            'normalizeCode' => ['code' => ReferralCodes::normalize($in['code'])],
            'decide' => self::decide($in),
            'scanSequence' => self::scanSequence($in),
            'stats' => self::stats($in, $storeFactory),
            default => throw new InvalidArgumentException('unknown fn '.$case['fn']),
        };
    }

    /** @return array<string,mixed> */
    private static function refusable(callable $fn): array
    {
        try {
            return $fn();
        } catch (InvalidInput $e) {
            return ['refused' => $e->field];
        }
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    private static function decide(array $in): array
    {
        $candidates = [];
        foreach ($in['candidates'] as $c) {
            $candidates[] = [
                new Scan($c['scanId'], $c['code'], $c['codeMemberId'], $c['scope'], 'h', self::at($c['scannedAt'])),
                new ReferralCode($c['code'], $c['codeMemberId'], $c['scope'], $c['status'], self::at($c['expiresAt'])),
            ];
        }
        $sponsors = $in['sponsors'];
        $decision = Attributor::decide(
            $candidates,
            $in['memberId'],
            self::at($in['signupAt']),
            AttributionPolicy::fromArray($in['policy']),
            static fn (string $id): ?string => $sponsors[$id] ?? null,
        );

        return [
            'attributed' => $decision->attributed,
            'sponsorMemberId' => $decision->sponsorMemberId,
            'code' => $decision->code,
            'scanId' => $decision->scanId,
            'reason' => $decision->reason,
        ];
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    private static function scanSequence(array $in): array
    {
        $store = new ArrayAttributionStore();
        foreach ($in['codes'] as $c) {
            $store->saveCode(new ReferralCode($c['code'], $c['memberId'], $c['scope'], $c['status'], self::at($c['expiresAt'])));
        }
        $program = new ReferralProgram(
            $store,
            new SessionHasher('fixture-key'),
            AttributionPolicy::fromArray(['dedupeSeconds' => $in['dedupeSeconds']]),
        );
        $results = [];
        foreach ($in['steps'] as $step) {
            $results[] = $program->recordScan($step['code'], $step['sessionId'], [], self::at($step['at']))->status;
        }

        return ['results' => $results];
    }

    /**
     * Seed a store with the fixture's shared stats data.
     */
    public static function seedStats(AttributionStore $store): void
    {
        $data = self::load()['statsData'];
        foreach ($data['scans'] as $s) {
            $store->saveScan(new Scan(null, $s['code'], $s['memberId'], $s['scope'], $s['sessionHash'], self::at($s['scannedAt'])));
        }
        foreach ($data['attributions'] as $a) {
            $store->saveAttributionRecord(new Attribution(
                $a['memberId'], $a['sponsorMemberId'], $a['code'], $a['scope'], $a['scanId'], $a['model'], self::at($a['attributedAt']),
            ));
        }
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    private static function stats(array $in, ?callable $storeFactory): array
    {
        $store = $storeFactory ? $storeFactory() : new ArrayAttributionStore();
        self::seedStats($store);
        $rows = $store->stats($in['groupBy'], self::at($in['from']), self::at($in['to']), $in['filter']);

        return ['rows' => array_map(static fn (StatRow $r): array => $r->toArray(), $rows)];
    }
}
