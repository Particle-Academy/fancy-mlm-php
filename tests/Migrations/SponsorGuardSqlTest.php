<?php

declare(strict_types=1);

use FancyMlm\Laravel\Database\SponsorGuard;

/*
 * MySQL/MariaDB and PostgreSQL cannot run in this suite, so their SQL is pinned
 * by shape here. SQLite runs for real in MigrationsTest and SponsorCorrectionTest.
 * This proves the text says the right thing; it does not prove the engine
 * accepts it.
 */

it('keeps the 0.4.0 guard SQL when no corrections table is given', function (string $driver) {
    $sql = implode("\n", SponsorGuard::installSql($driver, 'mlm_members'));

    expect($sql)->toContain(SponsorGuard::PERMANENT)->not->toContain('corrections');
})->with(['sqlite', 'mysql', 'pgsql']);

it('admits only the change the latest audit row for that member names', function (string $driver) {
    $sql = implode("\n", SponsorGuard::installSql($driver, 'mlm_members', 'mlm_sponsor_corrections'));

    expect($sql)
        ->toContain(SponsorGuard::PERMANENT)
        ->toContain(SponsorGuard::SELF)
        ->toContain('NOT EXISTS')
        ->toContain('MAX(c2.id)')
        ->toContain('c2.member_id = OLD.id')
        ->toContain('c.from_sponsor_id = OLD.sponsor_id')
        ->toContain('c.to_sponsor_id = NEW.sponsor_id');
})->with(['sqlite', 'mysql', 'pgsql']);

it('makes the audit table refuse UPDATE and DELETE (and TRUNCATE where a trigger can see it)', function (string $driver, array $events) {
    $sql = implode("\n", SponsorGuard::appendOnlySql($driver, 'mlm_sponsor_corrections'));

    expect($sql)->toContain(SponsorGuard::APPEND_ONLY);
    foreach ($events as $event) {
        expect($sql)->toContain($event);
    }
    expect(SponsorGuard::appendOnlyUninstallSql($driver, 'mlm_sponsor_corrections'))->not->toBeEmpty();
})->with([
    'sqlite' => ['sqlite', ['BEFORE UPDATE', 'BEFORE DELETE']],
    'mysql' => ['mysql', ['BEFORE UPDATE', 'BEFORE DELETE']],
    'pgsql' => ['pgsql', ['BEFORE UPDATE OR DELETE', 'BEFORE TRUNCATE']],
]);

it('emits nothing for a driver it has no trigger for', function () {
    expect(SponsorGuard::installSql('sqlsrv', 'mlm_members', 'mlm_sponsor_corrections'))->toBe([]);
    expect(SponsorGuard::appendOnlySql('sqlsrv', 'mlm_sponsor_corrections'))->toBe([]);
});

it('refuses a corrections table name it would have to escape', function () {
    SponsorGuard::installSql('sqlite', 'mlm_members', 'bad"name');
})->throws(InvalidArgumentException::class);
