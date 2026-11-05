<?php

declare(strict_types=1);

namespace FancyMlm\Laravel;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use FancyMlm\Attribution\ArrayAttributionStore;
use FancyMlm\Attribution\Attribution;
use FancyMlm\Attribution\Exceptions\SponsorImmutable;
use FancyMlm\Attribution\ReferralCode;
use FancyMlm\Attribution\Scan;
use FancyMlm\Attribution\StatRow;
use FancyMlm\Contracts\AttributionStore;
use FancyMlm\Laravel\Models\Attribution as AttributionModel;
use FancyMlm\Laravel\Models\Member as MemberModel;
use FancyMlm\Laravel\Models\ReferralCode as CodeModel;
use FancyMlm\Laravel\Models\ReferralScan as ScanModel;
use Illuminate\Database\Query\Builder;

/**
 * Eloquent-backed {@see AttributionStore}. Times are stored as UTC
 * `Y-m-d H:i:s` in DATETIME columns, which compare correctly as strings on
 * SQLite and natively on MySQL and PostgreSQL.
 */
class EloquentAttributionStore implements AttributionStore
{
    private const FORMAT = 'Y-m-d H:i:s';

    public function memberExists(string $memberId): bool
    {
        return MemberModel::query()->whereKey($memberId)->exists();
    }

    public function sponsorOf(string $memberId): ?string
    {
        $sponsor = MemberModel::query()->whereKey($memberId)->value('sponsor_id');

        return $sponsor === null ? null : (string) $sponsor;
    }

    /**
     * One conditional UPDATE (`... WHERE sponsor_id IS NULL`), so two racing
     * signups cannot both set a sponsor: the loser updates no row and throws.
     */
    public function assignSponsor(string $memberId, string $sponsorId): void
    {
        $model = new MemberModel();
        $affected = $model->newQuery()->toBase()
            ->where($model->getKeyName(), $memberId)
            ->whereNull('sponsor_id')
            ->update(['sponsor_id' => $sponsorId, $model->getUpdatedAtColumn() => self::fmt(new DateTimeImmutable())]);

        if ($affected === 0) {
            $current = $this->sponsorOf($memberId);
            if ($current !== $sponsorId) {
                throw SponsorImmutable::for($memberId, $current, $sponsorId);
            }
        }
    }

    public function findCode(string $code): ?ReferralCode
    {
        $row = CodeModel::query()->where('code', $code)->first();

        return $row === null ? null : new ReferralCode(
            $row->code,
            (string) $row->member_id,
            $row->scope,
            $row->status,
            self::parse($row->expires_at),
            self::parse($row->getRawOriginal('created_at')),
        );
    }

    public function saveCode(ReferralCode $code): ReferralCode
    {
        CodeModel::query()->create([
            'code' => $code->code,
            'member_id' => $code->memberId,
            'scope' => $code->scope,
            'status' => $code->status,
            'expires_at' => $code->expiresAt ? self::fmt($code->expiresAt) : null,
        ]);

        return $code;
    }

    public function revokeCode(string $code, DateTimeImmutable $at): bool
    {
        return CodeModel::query()->where('code', $code)->update([
            'status' => ReferralCode::REVOKED,
            'revoked_at' => self::fmt($at),
        ]) > 0;
    }

    public function latestScan(string $code, string $sessionHash): ?Scan
    {
        $row = ScanModel::query()
            ->where('code', $code)
            ->where('session_hash', $sessionHash)
            ->orderByDesc('scanned_at')
            ->orderByDesc('id')
            ->first();

        return $row === null ? null : self::toScan($row->getAttributes());
    }

    public function saveScan(Scan $scan): Scan
    {
        $row = ScanModel::query()->create([
            'code' => $scan->code,
            'member_id' => $scan->memberId,
            'scope' => $scan->scope,
            'session_hash' => $scan->sessionHash,
            'context' => $scan->context === [] ? null : json_encode($scan->context, JSON_THROW_ON_ERROR),
            'scanned_at' => self::fmt($scan->scannedAt),
        ]);

        return $scan->withId((string) $row->getKey());
    }

    /** One query: the session's scans joined to their codes. */
    public function candidatesForSession(string $sessionHash, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $scans = (new ScanModel())->getTable();
        $codes = (new CodeModel())->getTable();

        $rows = ScanModel::query()->toBase()
            ->join($codes, "{$codes}.code", '=', "{$scans}.code")
            ->where("{$scans}.session_hash", $sessionHash)
            ->where("{$scans}.scanned_at", '>=', self::fmt($from))
            ->where("{$scans}.scanned_at", '<=', self::fmt($to))
            ->orderBy("{$scans}.scanned_at")
            ->orderBy("{$scans}.id")
            ->select([
                "{$scans}.*",
                "{$codes}.member_id as code_member_id",
                "{$codes}.scope as code_scope",
                "{$codes}.status as code_status",
                "{$codes}.expires_at as code_expires_at",
            ])
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $out[] = [
                self::toScan($row),
                new ReferralCode($row['code'], (string) $row['code_member_id'], $row['code_scope'], $row['code_status'], self::parse($row['code_expires_at'])),
            ];
        }

        return $out;
    }

    public function attributionFor(string $memberId): ?Attribution
    {
        $row = AttributionModel::query()->where('member_id', $memberId)->first();

        return $row === null ? null : new Attribution(
            (string) $row->member_id,
            (string) $row->sponsor_member_id,
            $row->code,
            $row->scope,
            $row->scan_id === null ? null : (string) $row->scan_id,
            $row->model,
            self::parse($row->attributed_at),
        );
    }

    public function saveAttributionRecord(Attribution $attribution): void
    {
        AttributionModel::query()->create([
            'member_id' => $attribution->memberId,
            'sponsor_member_id' => $attribution->sponsorMemberId,
            'code' => $attribution->code,
            'scope' => $attribution->scope,
            'scan_id' => $attribution->scanId,
            'model' => $attribution->model,
            'attributed_at' => self::fmt($attribution->attributedAt),
        ]);
    }

    public function transaction(callable $fn): mixed
    {
        return (new ScanModel())->getConnection()->transaction($fn);
    }

    /**
     * Two grouped queries, whatever the number of keys: one over scans
     * (COUNT and COUNT DISTINCT session_hash), one over attributions.
     */
    public function stats(string $groupBy, DateTimeImmutable $from, DateTimeImmutable $to, array $filter = []): array
    {
        ArrayAttributionStore::groupField($groupBy);
        $scanKey = ['code' => 'code', 'scope' => 'scope', 'member' => 'member_id'][$groupBy];
        $signupKey = ['code' => 'code', 'scope' => 'scope', 'member' => 'sponsor_member_id'][$groupBy];

        $scanRows = $this->filtered(ScanModel::query()->toBase(), 'scanned_at', 'member_id', $from, $to, $filter)
            ->select("{$scanKey} as k")
            ->selectRaw('count(*) as scans, count(distinct session_hash) as sessions')
            ->groupBy($scanKey)
            ->get();

        $signupRows = $this->filtered(AttributionModel::query()->toBase(), 'attributed_at', 'sponsor_member_id', $from, $to, $filter)
            ->select("{$signupKey} as k")
            ->selectRaw('count(*) as signups')
            ->groupBy($signupKey)
            ->get();

        $aggregates = [];
        foreach ($scanRows as $row) {
            $aggregates[(string) $row->k] = ['scans' => (int) $row->scans, 'sessions' => (int) $row->sessions];
        }
        $signups = [];
        foreach ($signupRows as $row) {
            $signups[(string) $row->k] = (int) $row->signups;
        }

        return StatRow::merge($aggregates, $signups);
    }

    public function pruneScans(DateTimeImmutable $before): int
    {
        return ScanModel::query()->where('scanned_at', '<', self::fmt($before))->delete();
    }

    /** @param array<string,string> $filter */
    private function filtered(Builder $query, string $timeColumn, string $memberColumn, DateTimeImmutable $from, DateTimeImmutable $to, array $filter): Builder
    {
        return $query
            ->where($timeColumn, '>=', self::fmt($from))
            ->where($timeColumn, '<', self::fmt($to))
            ->when(isset($filter['code']), fn (Builder $q) => $q->where('code', $filter['code']))
            ->when(isset($filter['scope']), fn (Builder $q) => $q->where('scope', $filter['scope']))
            ->when(isset($filter['member']), fn (Builder $q) => $q->where($memberColumn, $filter['member']));
    }

    /** @param array<string,mixed> $row */
    private static function toScan(array $row): Scan
    {
        return new Scan(
            (string) $row['id'],
            (string) $row['code'],
            (string) $row['member_id'],
            (string) $row['scope'],
            (string) $row['session_hash'],
            self::parse((string) $row['scanned_at']),
            $row['context'] === null ? [] : (array) json_decode((string) $row['context'], true, 512, JSON_THROW_ON_ERROR),
        );
    }

    private static function fmt(DateTimeInterface $at): string
    {
        return DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'))->format(self::FORMAT);
    }

    private static function parse(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'));
        }

        return new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
    }
}
