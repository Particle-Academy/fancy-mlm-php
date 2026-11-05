<?php

declare(strict_types=1);

namespace FancyMlm\Contracts;

use DateTimeImmutable;
use FancyMlm\Attribution\Attribution;
use FancyMlm\Attribution\ReferralCode;
use FancyMlm\Attribution\Scan;
use FancyMlm\Attribution\StatRow;

/**
 * Port for referral codes, scans and attributions. The Laravel bridge ships an
 * Eloquent implementation; {@see \FancyMlm\Attribution\ArrayAttributionStore}
 * backs plain-PHP hosts and tests.
 *
 * Implementations store exactly what they are given. In particular they never
 * add an IP, a user agent or a raw session id of their own.
 */
interface AttributionStore
{
    public function memberExists(string $memberId): bool;

    public function sponsorOf(string $memberId): ?string;

    /**
     * Set a member's sponsor. Must succeed only while the sponsor is unset,
     * atomically, and throw {@see \FancyMlm\Attribution\Exceptions\SponsorImmutable}
     * otherwise, so two racing writers cannot both win.
     */
    public function assignSponsor(string $memberId, string $sponsorId): void;

    public function findCode(string $code): ?ReferralCode;

    public function saveCode(ReferralCode $code): ReferralCode;

    public function revokeCode(string $code, DateTimeImmutable $at): bool;

    /** The most recent recorded scan of this code by this session hash. */
    public function latestScan(string $code, string $sessionHash): ?Scan;

    public function saveScan(Scan $scan): Scan;

    /**
     * This session's scans in [from, to], each with its code, ordered by
     * scannedAt then insertion order.
     *
     * @return list<array{0:Scan,1:ReferralCode}>
     */
    public function candidatesForSession(string $sessionHash, DateTimeImmutable $from, DateTimeImmutable $to): array;

    public function attributionFor(string $memberId): ?Attribution;

    public function saveAttributionRecord(Attribution $attribution): void;

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed;

    /**
     * Stats over [from, to), grouped by 'code', 'scope' or 'member', optionally
     * filtered by `['code' => ..., 'scope' => ..., 'member' => ...]`.
     *
     * @param array<string,string> $filter
     * @return list<StatRow>
     */
    public function stats(string $groupBy, DateTimeImmutable $from, DateTimeImmutable $to, array $filter = []): array;

    /** Delete scans with scannedAt before `$before`; returns how many. */
    public function pruneScans(DateTimeImmutable $before): int;
}
