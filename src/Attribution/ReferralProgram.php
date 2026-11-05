<?php

declare(strict_types=1);

namespace FancyMlm\Attribution;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use FancyMlm\Attribution\Exceptions\InvalidInput;
use FancyMlm\Attribution\Exceptions\UnknownMember;
use FancyMlm\Contracts\AttributionStore;
use JsonException;

/**
 * Referral codes, scan logging, scan-to-signup attribution and stats, over an
 * {@see AttributionStore}. Framework-free; the Laravel bridge wraps it.
 *
 * Privacy: a scan stores the code, its owner and scope, a keyed hash of the
 * session id, the time, and whatever context the host passes. Nothing else.
 * The package never reads an IP or a user agent. A host that wants either must
 * put it in `$context` itself, and then owns what that means: the scans table
 * becomes personal data under GDPR and similar law, and the retention it sets
 * becomes a legal obligation rather than a preference.
 */
final class ReferralProgram
{
    private const ISSUE_ATTEMPTS = 5;

    /** @var Closure():DateTimeImmutable */
    private readonly Closure $clock;

    /** @param (Closure():DateTimeImmutable)|null $clock */
    public function __construct(
        private readonly AttributionStore $store,
        private readonly SessionHasher $hasher,
        private readonly AttributionPolicy $policy = new AttributionPolicy(),
        ?Closure $clock = null,
        private readonly int $codeLength = ReferralCodes::DEFAULT_LENGTH,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function policy(): AttributionPolicy
    {
        return $this->policy;
    }

    /**
     * Issue a new code for a member in a host-defined scope.
     *
     * @param string|list<string> $scope opaque string or tuple, see {@see Scope}
     */
    public function issueCode(string $memberId, string|array $scope, ?DateTimeImmutable $expiresAt = null): ReferralCode
    {
        if (! $this->store->memberExists($memberId)) {
            throw UnknownMember::for($memberId);
        }
        $scopeKey = Scope::key($scope);

        for ($attempt = 0; $attempt < self::ISSUE_ATTEMPTS; $attempt++) {
            $code = ReferralCodes::generate($this->codeLength);
            if ($this->store->findCode($code) === null) {
                return $this->store->saveCode(new ReferralCode($code, $memberId, $scopeKey, ReferralCode::ACTIVE, $expiresAt, $this->now()));
            }
        }

        // 80 bits colliding five times running means the CSPRNG is broken.
        throw new InvalidInput('code', 'Could not generate an unused referral code.');
    }

    public function revokeCode(string $code): bool
    {
        $normalized = ReferralCodes::normalize($code);

        return $normalized !== null && $this->store->revokeCode($normalized, $this->now());
    }

    /**
     * Log a scan or click. A repeat from the same session inside the dedupe
     * window is reported as `duplicate` and not stored, so refreshes do not
     * inflate counts.
     *
     * @param array<string,mixed> $context host-supplied only; stored as JSON
     */
    public function recordScan(string $code, string $sessionId, array $context = [], ?DateTimeImmutable $at = null): ScanResult
    {
        $at ??= $this->now();
        self::assertJson($context);

        $normalized = ReferralCodes::normalize($code);
        $found = $normalized === null ? null : $this->store->findCode($normalized);
        if ($found === null) {
            return new ScanResult('unknown_code');
        }
        if (! $found->usableAt($at)) {
            return new ScanResult('code_inactive');
        }

        $hash = $this->hasher->hash($sessionId);
        $last = $this->store->latestScan($found->code, $hash);
        if ($last !== null && $last->scannedAt->getTimestamp() > $at->getTimestamp() - $this->policy->dedupeSeconds) {
            return new ScanResult('duplicate', $last);
        }

        return new ScanResult('recorded', $this->store->saveScan(
            new Scan(null, $found->code, $found->memberId, $found->scope, $hash, $at, $context),
        ));
    }

    /**
     * Match a new member's signup to the session's scans and, if one is
     * eligible, set their sponsor permanently. Safe to call more than once: a
     * repeat returns the original attribution and changes nothing.
     */
    public function attributeSignup(string $memberId, string $sessionId, ?DateTimeImmutable $at = null): AttributionDecision
    {
        $at ??= $this->now();
        if (! $this->store->memberExists($memberId)) {
            throw UnknownMember::for($memberId);
        }

        $existing = $this->store->attributionFor($memberId);
        if ($existing !== null) {
            return new AttributionDecision(true, 'already_attributed', $existing->sponsorMemberId, $existing->code, $existing->scope, $existing->scanId);
        }
        $current = $this->store->sponsorOf($memberId);
        if ($current !== null) {
            return AttributionDecision::none('already_sponsored', $current);
        }

        $hash = $this->hasher->hash($sessionId);
        $from = $at->setTimestamp($at->getTimestamp() - $this->policy->windowSeconds);
        $decision = Attributor::decide(
            $this->store->candidatesForSession($hash, $from, $at),
            $memberId,
            $at,
            $this->policy,
            fn (string $id): ?string => $this->store->sponsorOf($id),
        );

        if ($decision->attributed) {
            $this->store->transaction(function () use ($memberId, $decision, $at): void {
                $this->store->assignSponsor($memberId, (string) $decision->sponsorMemberId);
                $this->store->saveAttributionRecord(new Attribution(
                    $memberId,
                    (string) $decision->sponsorMemberId,
                    (string) $decision->code,
                    (string) $decision->scope,
                    $decision->scanId,
                    $this->policy->model,
                    $at,
                ));
            });
        }

        return $decision;
    }

    /**
     * Set a sponsor directly, without a scan. Once only: a change fails with
     * SponsorImmutable; self-sponsorship and cycles fail too.
     */
    public function assignSponsor(string $memberId, string $sponsorId): void
    {
        foreach ([$memberId, $sponsorId] as $id) {
            if (! $this->store->memberExists($id)) {
                throw UnknownMember::for($id);
            }
        }

        $needed = SponsorRules::assertAssignable(
            $memberId,
            $sponsorId,
            $this->store->sponsorOf($memberId),
            fn (string $id): ?string => $this->store->sponsorOf($id),
        );
        if ($needed) {
            $this->store->assignSponsor($memberId, $sponsorId);
        }
    }

    /**
     * @param 'code'|'scope'|'member' $groupBy
     * @param array<string,string> $filter
     * @return list<StatRow>
     */
    public function stats(string $groupBy, DateTimeImmutable $from, DateTimeImmutable $to, array $filter = []): array
    {
        ArrayAttributionStore::groupField($groupBy);

        return $this->store->stats($groupBy, $from, $to, $filter);
    }

    /**
     * Delete scans older than the retention period. Refuses a retention shorter
     * than the attribution window: a pruned scan cannot attribute, so that
     * setting would quietly shorten the window instead.
     */
    public function pruneScans(int $retentionSeconds, ?DateTimeImmutable $now = null): int
    {
        if ($retentionSeconds < $this->policy->windowSeconds) {
            throw new InvalidInput('retention', sprintf(
                'Scan retention (%d s) is shorter than the attribution window (%d s); shorten the window first.',
                $retentionSeconds,
                $this->policy->windowSeconds,
            ));
        }
        $now ??= $this->now();

        return $this->store->pruneScans($now->setTimestamp($now->getTimestamp() - $retentionSeconds));
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock)();
    }

    /** @param array<string,mixed> $context */
    private static function assertJson(array $context): void
    {
        try {
            json_encode($context, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidInput('context', 'Scan context must be JSON-encodable: '.$e->getMessage());
        }
    }
}
