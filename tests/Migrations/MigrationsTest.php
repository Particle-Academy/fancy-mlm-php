<?php

declare(strict_types=1);

use FancyMlm\Laravel\Models\Member as MemberModel;
use FancyMlm\Tests\Laravel\TestCase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Up, down and up again on SQLite, outside RefreshDatabase so the rollback is
 * real rather than inside a wrapping transaction.
 *
 * The package ships seven migrations: members (0.1), codes, scans,
 * attributions, the 0.4.0 sponsor guard, the sponsor-corrections table and the
 * guard that admits an audited correction.
 */
uses(TestCase::class);

function sponsorTriggers(): array
{
    return triggersOn('mlm_members');
}

function triggersOn(string $table): array
{
    return array_map(
        static fn ($row) => $row->name,
        DB::select("select name from sqlite_master where type = 'trigger' and tbl_name = ?", [$table]),
    );
}

function sponsorTriggerSql(): string
{
    return (string) DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'mlm_members')->value('sql');
}

it('migrates up, down and up again, taking the sponsor guard with it', function () {
    $this->artisan('migrate')->assertExitCode(0);
    expect(Schema::hasTable('mlm_referral_scans'))->toBeTrue();
    expect(Schema::hasTable('mlm_sponsor_corrections'))->toBeTrue();
    expect(sponsorTriggers())->not->toBeEmpty();

    $this->artisan('migrate:rollback', ['--step' => 6])->assertExitCode(0);
    expect(Schema::hasTable('mlm_referral_codes'))->toBeFalse();
    expect(Schema::hasTable('mlm_referral_scans'))->toBeFalse();
    expect(Schema::hasTable('mlm_attributions'))->toBeFalse();
    expect(Schema::hasTable('mlm_sponsor_corrections'))->toBeFalse();
    expect(Schema::hasTable('mlm_members'))->toBeTrue();
    expect(sponsorTriggers())->toBeEmpty();

    // with the guard rolled back, the database alone no longer refuses
    $a = MemberModel::create();
    $b = MemberModel::create();
    $m = MemberModel::create(['sponsor_id' => $a->id]);
    DB::table('mlm_members')->where('id', $m->id)->update(['sponsor_id' => $b->id]);
    DB::table('mlm_members')->where('id', $m->id)->update(['sponsor_id' => $a->id]);

    $this->artisan('migrate')->assertExitCode(0);
    expect(sponsorTriggers())->not->toBeEmpty();
    expect(fn () => DB::table('mlm_members')->where('id', $m->id)->update(['sponsor_id' => $b->id]))
        ->toThrow(QueryException::class, 'sponsor_id is permanent');

    $this->artisan('migrate:rollback', ['--step' => 7])->assertExitCode(0);
    expect(Schema::hasTable('mlm_members'))->toBeFalse();
});

it('rolls the correction migrations back to the 0.4.0 guard, and forward again', function () {
    $this->artisan('migrate')->assertExitCode(0);
    expect(sponsorTriggerSql())->toContain('mlm_sponsor_corrections');
    expect(triggersOn('mlm_sponsor_corrections'))->toHaveCount(2);

    $this->artisan('migrate:rollback', ['--step' => 2])->assertExitCode(0);
    expect(Schema::hasTable('mlm_sponsor_corrections'))->toBeFalse();
    expect(sponsorTriggers())->toHaveCount(1);
    expect(sponsorTriggerSql())->not->toContain('mlm_sponsor_corrections');

    // the 0.4.0 guard is back and still refuses
    $a = MemberModel::create();
    $b = MemberModel::create();
    $m = MemberModel::create(['sponsor_id' => $a->id]);
    expect(fn () => DB::table('mlm_members')->where('id', $m->id)->update(['sponsor_id' => $b->id]))
        ->toThrow(QueryException::class, 'sponsor_id is permanent');

    $this->artisan('migrate')->assertExitCode(0);
    expect(sponsorTriggerSql())->toContain('mlm_sponsor_corrections');
    expect(triggersOn('mlm_sponsor_corrections'))->toHaveCount(2);
    expect(fn () => DB::table('mlm_members')->where('id', $m->id)->update(['sponsor_id' => $b->id]))
        ->toThrow(QueryException::class, 'sponsor_id is permanent');
});

it('skips the database guard when the host turns it off, leaving the code guard', function () {
    config(['mlm.attribution.database_guard' => false]);

    $this->artisan('migrate')->assertExitCode(0);

    expect(sponsorTriggers())->toBeEmpty();
    expect(Schema::hasTable('mlm_sponsor_corrections'))->toBeTrue();
    expect(triggersOn('mlm_sponsor_corrections'))->toBeEmpty();

    $a = MemberModel::create();
    $b = MemberModel::create();
    $m = MemberModel::create(['sponsor_id' => $a->id]);
    expect(fn () => $m->update(['sponsor_id' => $b->id]))
        ->toThrow(FancyMlm\Attribution\Exceptions\SponsorImmutable::class);

    $this->artisan('migrate:rollback', ['--step' => 7])->assertExitCode(0);
    expect(Schema::hasTable('mlm_members'))->toBeFalse();
});
