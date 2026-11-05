<?php

declare(strict_types=1);

namespace FancyMlm\Attribution;

/**
 * Referral stats for one key (a code, a scope or a member) over a range.
 *
 * `conversionRate` is signups / uniqueSessions, and 0 when there were no
 * sessions. It can exceed 1: a signup inside the range may come from a scan
 * before it.
 */
final class StatRow
{
    public readonly float $conversionRate;

    public function __construct(
        public readonly string $key,
        public readonly int $scans,
        public readonly int $uniqueSessions,
        public readonly int $signups,
    ) {
        $this->conversionRate = $uniqueSessions > 0 ? $signups / $uniqueSessions : 0.0;
    }

    /** @return array{key:string,scans:int,uniqueSessions:int,signups:int,conversionRate:float} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'scans' => $this->scans,
            'uniqueSessions' => $this->uniqueSessions,
            'signups' => $this->signups,
            'conversionRate' => $this->conversionRate,
        ];
    }

    /**
     * Merge per-key scan aggregates and signup counts into sorted rows.
     *
     * @param array<string,array{scans:int,sessions:int}> $scanAggregates
     * @param array<string,int> $signups
     * @return list<self>
     */
    public static function merge(array $scanAggregates, array $signups): array
    {
        $keys = array_unique(array_merge(array_map('strval', array_keys($scanAggregates)), array_map('strval', array_keys($signups))));
        sort($keys, SORT_STRING);

        $rows = [];
        foreach ($keys as $key) {
            $agg = $scanAggregates[$key] ?? ['scans' => 0, 'sessions' => 0];
            $rows[] = new self($key, (int) $agg['scans'], (int) $agg['sessions'], (int) ($signups[$key] ?? 0));
        }

        return $rows;
    }
}
