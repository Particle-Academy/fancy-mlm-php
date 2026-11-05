<?php

declare(strict_types=1);

namespace FancyMlm\Attribution;

use DateTimeImmutable;
use FancyMlm\Attribution\Exceptions\InvalidInput;
use FancyMlm\Attribution\Exceptions\SponsorImmutable;
use FancyMlm\Contracts\AttributionStore;

/** In-memory {@see AttributionStore} for plain-PHP hosts and tests. */
final class ArrayAttributionStore implements AttributionStore
{
    /** @var array<string,?string> member id => sponsor id */
    private array $members = [];

    /** @var array<string,ReferralCode> */
    private array $codes = [];

    /** @var list<Scan> */
    private array $scans = [];

    /** @var array<string,Attribution> */
    private array $attributions = [];

    private int $nextScanId = 1;

    public function addMember(string $id, ?string $sponsorId = null): self
    {
        $this->members[$id] = $sponsorId;

        return $this;
    }

    public function scanCount(): int
    {
        return count($this->scans);
    }

    public function memberExists(string $memberId): bool
    {
        return array_key_exists($memberId, $this->members);
    }

    public function sponsorOf(string $memberId): ?string
    {
        return $this->members[$memberId] ?? null;
    }

    public function assignSponsor(string $memberId, string $sponsorId): void
    {
        $current = $this->members[$memberId] ?? null;
        if ($current !== null && $current !== $sponsorId) {
            throw SponsorImmutable::for($memberId, $current, $sponsorId);
        }
        $this->members[$memberId] = $sponsorId;
    }

    public function findCode(string $code): ?ReferralCode
    {
        return $this->codes[$code] ?? null;
    }

    public function saveCode(ReferralCode $code): ReferralCode
    {
        if (isset($this->codes[$code->code])) {
            throw new InvalidInput('code', 'That code already exists.');
        }

        return $this->codes[$code->code] = $code;
    }

    public function revokeCode(string $code, DateTimeImmutable $at): bool
    {
        $existing = $this->codes[$code] ?? null;
        if ($existing === null) {
            return false;
        }
        $this->codes[$code] = new ReferralCode($existing->code, $existing->memberId, $existing->scope, ReferralCode::REVOKED, $existing->expiresAt, $existing->createdAt);

        return true;
    }

    public function latestScan(string $code, string $sessionHash): ?Scan
    {
        $latest = null;
        foreach ($this->scans as $scan) {
            if ($scan->code === $code && $scan->sessionHash === $sessionHash
                && ($latest === null || $scan->scannedAt >= $latest->scannedAt)) {
                $latest = $scan;
            }
        }

        return $latest;
    }

    public function saveScan(Scan $scan): Scan
    {
        $saved = $scan->withId((string) $this->nextScanId++);
        $this->scans[] = $saved;

        return $saved;
    }

    public function candidatesForSession(string $sessionHash, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $out = [];
        foreach ($this->scans as $scan) {
            if ($scan->sessionHash === $sessionHash && $scan->scannedAt >= $from && $scan->scannedAt <= $to
                && isset($this->codes[$scan->code])) {
                $out[] = [$scan, $this->codes[$scan->code]];
            }
        }
        usort($out, static fn (array $a, array $b): int => $a[0]->scannedAt <=> $b[0]->scannedAt);

        return $out;
    }

    public function attributionFor(string $memberId): ?Attribution
    {
        return $this->attributions[$memberId] ?? null;
    }

    public function saveAttributionRecord(Attribution $attribution): void
    {
        if (isset($this->attributions[$attribution->memberId])) {
            throw SponsorImmutable::for($attribution->memberId, $this->attributions[$attribution->memberId]->sponsorMemberId, $attribution->sponsorMemberId);
        }
        $this->attributions[$attribution->memberId] = $attribution;
    }

    public function transaction(callable $fn): mixed
    {
        $snapshot = [$this->members, $this->attributions];
        try {
            return $fn();
        } catch (\Throwable $e) {
            [$this->members, $this->attributions] = $snapshot;
            throw $e;
        }
    }

    public function stats(string $groupBy, DateTimeImmutable $from, DateTimeImmutable $to, array $filter = []): array
    {
        $field = self::groupField($groupBy);

        $aggregates = [];
        $sessions = [];
        foreach ($this->scans as $scan) {
            if ($scan->scannedAt < $from || $scan->scannedAt >= $to || ! self::matches($filter, $scan->code, $scan->scope, $scan->memberId)) {
                continue;
            }
            $key = match ($field) {
                'code' => $scan->code,
                'scope' => $scan->scope,
                'member' => $scan->memberId,
            };
            $aggregates[$key] ??= ['scans' => 0, 'sessions' => 0];
            $aggregates[$key]['scans']++;
            if (! isset($sessions[$key][$scan->sessionHash])) {
                $sessions[$key][$scan->sessionHash] = true;
                $aggregates[$key]['sessions']++;
            }
        }

        $signups = [];
        foreach ($this->attributions as $a) {
            if ($a->attributedAt < $from || $a->attributedAt >= $to || ! self::matches($filter, $a->code, $a->scope, $a->sponsorMemberId)) {
                continue;
            }
            $key = match ($field) {
                'code' => $a->code,
                'scope' => $a->scope,
                'member' => $a->sponsorMemberId,
            };
            $signups[$key] = ($signups[$key] ?? 0) + 1;
        }

        return StatRow::merge($aggregates, $signups);
    }

    public function pruneScans(DateTimeImmutable $before): int
    {
        $kept = array_values(array_filter($this->scans, static fn (Scan $s): bool => $s->scannedAt >= $before));
        $deleted = count($this->scans) - count($kept);
        $this->scans = $kept;

        return $deleted;
    }

    public static function groupField(string $groupBy): string
    {
        if (! in_array($groupBy, ['code', 'scope', 'member'], true)) {
            throw new InvalidInput('groupBy', "Stats group by code, scope or member, not {$groupBy}.");
        }

        return $groupBy;
    }

    /** @param array<string,string> $filter */
    private static function matches(array $filter, string $code, string $scope, string $memberId): bool
    {
        return (! isset($filter['code']) || $filter['code'] === $code)
            && (! isset($filter['scope']) || $filter['scope'] === $scope)
            && (! isset($filter['member']) || (string) $filter['member'] === $memberId);
    }
}
