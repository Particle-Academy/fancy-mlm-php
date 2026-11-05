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
 */
uses(TestCase::class);

function sponsorTriggers(): array
{
    return array_map(
        static fn ($row) => $row->name,
        DB::select("select name from sqlite_master where type = 'trigger' and tbl_name = 'mlm_members'"),
    );
}

it('migrates up, down and up again, taking the sponsor guard with it', function () {
    $this->artisan('migrate')->assertExitCode(0);
    expect(Schema::hasTable('mlm_referral_scans'))->toBeTrue();
    expect(sponsorTriggers())->not->toBeEmpty();

    $this->artisan('migrate:rollback', ['--step' => 4])->assertExitCode(0);
    expect(Schema::hasTable('mlm_referral_codes'))->toBeFalse();
    expect(Schema::hasTable('mlm_referral_scans'))->toBeFalse();
    expect(Schema::hasTable('mlm_attributions'))->toBeFalse();
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

    $this->artisan('migrate:rollback', ['--step' => 5])->assertExitCode(0);
    expect(Schema::hasTable('mlm_members'))->toBeFalse();
});

it('skips the database guard when the host turns it off, leaving the code guard', function () {
    config(['mlm.attribution.database_guard' => false]);

    $this->artisan('migrate')->assertExitCode(0);

    expect(sponsorTriggers())->toBeEmpty();

    $a = MemberModel::create();
    $b = MemberModel::create();
    $m = MemberModel::create(['sponsor_id' => $a->id]);
    expect(fn () => $m->update(['sponsor_id' => $b->id]))
        ->toThrow(FancyMlm\Attribution\Exceptions\SponsorImmutable::class);
});
