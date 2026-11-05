<?php

declare(strict_types=1);

use FancyMlm\Attribution\Exceptions\CorrectionUnauthorized;
use FancyMlm\Attribution\Exceptions\SponsorImmutable;
use FancyMlm\Attribution\SponsorCorrection;
use FancyMlm\Attribution\SponsorCorrectionRequest;
use FancyMlm\Laravel\EloquentAttributionStore;
use FancyMlm\Laravel\Events\SponsorCorrected;
use FancyMlm\Laravel\Facades\Mlm;
use FancyMlm\Laravel\GateCorrectionAuthorizer;
use FancyMlm\Laravel\Models\Member as MemberModel;
use FancyMlm\Tests\Support\CorrectionFixture;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

/** Three members: m sponsored by a, and b free to move m under. */
function correctionTrio(): array
{
    $a = MemberModel::create();
    $b = MemberModel::create();
    $m = MemberModel::create(['sponsor_id' => $a->id]);

    return [$a, $b, $m];
}

function allowCorrections(): void
{
    Gate::define(GateCorrectionAuthorizer::ABILITY, fn (?object $user, SponsorCorrectionRequest $request): bool => true);
}

/** Insert an audit row directly, as anything with raw write access could. */
function rawCorrection(int $member, int $from, int $to): void
{
    DB::table('mlm_sponsor_corrections')->insert([
        'member_id' => $member,
        'from_sponsor_id' => $from,
        'to_sponsor_id' => $to,
        'reason' => 'raw',
        'actor_id' => 'raw',
        'corrected_at' => '2026-11-04 12:00:00',
    ]);
}

describe('authorization is the host Gate, deny by default', function () {
    it('names the ability the host defines', function () {
        expect(GateCorrectionAuthorizer::ABILITY)->toBe('fancy-mlm.correct-sponsor');
    });

    it('refuses when the host never defined the Gate', function () {
        [, $b, $m] = correctionTrio();

        expect(fn () => Mlm::correctSponsor((string) $m->id, (string) $b->id, 'ticket 1', 'admin-7'))
            ->toThrow(CorrectionUnauthorized::class);
        expect($m->fresh()->sponsor_id)->not->toBe($b->id);
    });

    it('refuses an undefined Gate even when a before() hook would allow every ability', function () {
        [, $b, $m] = correctionTrio();
        $this->actingAs(new GenericUser(['id' => 1]));
        Gate::before(fn () => true); // the common "super admin" shortcut

        expect(fn () => Mlm::correctSponsor((string) $m->id, (string) $b->id, 'ticket 1', 'admin-7'))
            ->toThrow(CorrectionUnauthorized::class);
        expect(DB::table('mlm_sponsor_corrections')->count())->toBe(0);
    });

    it('refuses when the Gate says no', function () {
        [, $b, $m] = correctionTrio();
        Gate::define(GateCorrectionAuthorizer::ABILITY, fn (?object $user, SponsorCorrectionRequest $r): bool => false);

        expect(fn () => Mlm::correctSponsor((string) $m->id, (string) $b->id, 'ticket 1', 'admin-7'))
            ->toThrow(CorrectionUnauthorized::class);
    });

    it('asks the Gate as the signed-in user, with the request', function () {
        [, $b, $m] = correctionTrio();
        $this->actingAs(new GenericUser(['id' => 42]));
        $seen = [];
        Gate::define(GateCorrectionAuthorizer::ABILITY, function (GenericUser $user, SponsorCorrectionRequest $r) use (&$seen): bool {
            $seen = [$user->getAuthIdentifier(), $r->memberId, $r->toSponsorId, $r->actorId];

            return true;
        });

        Mlm::correctSponsor((string) $m->id, (string) $b->id, 'ticket 1', 'admin-42');

        expect($seen)->toBe([42, (string) $m->id, (string) $b->id, 'admin-42']);
    });
});

describe('Mlm::correctSponsor', function () {
    it('moves the sponsor and appends one audit row in mlm_sponsor_corrections', function () {
        [$a, $b, $m] = correctionTrio();
        allowCorrections();

        $c = Mlm::correctSponsor((string) $m->id, (string) $b->id, 'Support ticket 4411', 'admin-7');

        expect($m->fresh()->sponsor_id)->toBe($b->id);
        $row = DB::table('mlm_sponsor_corrections')->first();
        expect([(int) $row->member_id, (int) $row->from_sponsor_id, (int) $row->to_sponsor_id, $row->reason, $row->actor_id])
            ->toBe([$m->id, $a->id, $b->id, 'Support ticket 4411', 'admin-7']);
        expect($row->corrected_at)->not->toBeNull();
        expect($c->id)->toBe((string) $row->id);
        expect(Mlm::sponsorCorrections((string) $m->id))->toHaveCount(1);
    });

    it('dispatches SponsorCorrected after it commits', function () {
        Event::fake([SponsorCorrected::class]);
        [, $b, $m] = correctionTrio();
        allowCorrections();

        Mlm::correctSponsor((string) $m->id, (string) $b->id, 'ticket 1', 'admin-7');

        Event::assertDispatched(SponsorCorrected::class, fn (SponsorCorrected $e) => $e->correction->toSponsorId === (string) $b->id);
        expect(new SponsorCorrected(new SponsorCorrection('1', 'm', 'a', 'b', 'r', 'x', new DateTimeImmutable())))
            ->toBeInstanceOf(Illuminate\Contracts\Events\ShouldDispatchAfterCommit::class);
    });

    it('dispatches nothing when refused', function () {
        Event::fake([SponsorCorrected::class]);
        [, $b, $m] = correctionTrio();

        try {
            Mlm::correctSponsor((string) $m->id, (string) $b->id, 'ticket 1', 'admin-7');
        } catch (CorrectionUnauthorized) {
        }

        Event::assertNotDispatched(SponsorCorrected::class);
    });

    it('writes nothing when the sponsor moved underneath it', function () {
        [$a, $b, $m] = correctionTrio();
        $store = app(EloquentAttributionStore::class);
        $stale = new SponsorCorrection(null, (string) $m->id, (string) $b->id, (string) $a->id, 'stale', 'admin-7', new DateTimeImmutable());

        expect(fn () => $store->applySponsorCorrection($stale))->toThrow(SponsorImmutable::class);
        expect(DB::table('mlm_sponsor_corrections')->count())->toBe(0);
        expect($m->fresh()->sponsor_id)->toBe($a->id);
    });

    it('passes the shared rows against the database, trigger included', function (array $case) {
        allowCorrections();
        $ids = [];
        foreach (array_keys($case['input']['members']) as $key) {
            $ids[(string) $key] = (string) MemberModel::create()->id;
        }
        foreach ($case['input']['members'] as $key => $sponsor) {
            if ($sponsor !== null) {
                DB::table('mlm_members')->where('id', $ids[(string) $key])->update(['sponsor_id' => $ids[$sponsor]]);
            }
        }
        $back = array_flip($ids);

        $actual = CorrectionFixture::runOn(
            $case,
            app(EloquentAttributionStore::class),
            fn (string $id): string => $ids[$id] ?? '999999',
            fn (?string $id): ?string => $id === null ? null : $back[$id],
        );

        expect($actual)->toBe(CorrectionFixture::expected($case));
    })->with(function (): array {
        $rows = [];
        foreach (CorrectionFixture::load()['cases'] as $case) {
            $rows[$case['id']] = [$case];
        }

        return $rows;
    });
});

describe('every normal path stays locked after a correction', function () {
    it('refuses the facade, the model and a raw update', function () {
        [$a, $b, $m] = correctionTrio();
        allowCorrections();
        Mlm::correctSponsor((string) $m->id, (string) $b->id, 'ticket 1', 'admin-7');

        expect(fn () => Mlm::assignSponsor((string) $m->id, (string) $a->id))->toThrow(SponsorImmutable::class);
        expect(fn () => $m->fresh()->update(['sponsor_id' => $a->id]))->toThrow(SponsorImmutable::class);
        expect(fn () => DB::table('mlm_members')->where('id', $m->id)->update(['sponsor_id' => $a->id]))
            ->toThrow(QueryException::class, 'sponsor_id is permanent');
        expect($m->fresh()->sponsor_id)->toBe($b->id);
    });

    it('refuses the model even when a matching audit row exists', function () {
        [, $b, $m] = correctionTrio();
        rawCorrection($m->id, $m->sponsor_id, $b->id);

        $m->update(['sponsor_id' => $b->id]);
    })->throws(SponsorImmutable::class);
});

describe('the database trigger allows only the audited change', function () {
    it('refuses a raw change with no audit row', function () {
        [, $b, $m] = correctionTrio();

        expect(fn () => DB::table('mlm_members')->where('id', $m->id)->update(['sponsor_id' => $b->id]))
            ->toThrow(QueryException::class, 'sponsor_id is permanent');
    });

    it('refuses a raw change to a different target than the audit row names', function () {
        [$a, $b, $m] = correctionTrio();
        $c = MemberModel::create();
        rawCorrection($m->id, $a->id, $b->id);

        expect(fn () => DB::table('mlm_members')->where('id', $m->id)->update(['sponsor_id' => $c->id]))
            ->toThrow(QueryException::class, 'sponsor_id is permanent');
    });

    it('refuses a raw change from a different sponsor than the audit row names', function () {
        [, $b, $m] = correctionTrio();
        $c = MemberModel::create();
        rawCorrection($m->id, $c->id, $b->id);

        expect(fn () => DB::table('mlm_members')->where('id', $m->id)->update(['sponsor_id' => $b->id]))
            ->toThrow(QueryException::class, 'sponsor_id is permanent');
    });

    it('refuses a raw change borrowing another member\'s audit row', function () {
        [$a, $b, $m] = correctionTrio();
        $other = MemberModel::create(['sponsor_id' => $a->id]);
        rawCorrection($other->id, $a->id, $b->id);

        expect(fn () => DB::table('mlm_members')->where('id', $m->id)->update(['sponsor_id' => $b->id]))
            ->toThrow(QueryException::class, 'sponsor_id is permanent');
    });

    it('refuses a replay of an older audit row once a later one exists', function () {
        [$a, $b, $m] = correctionTrio();
        allowCorrections();
        Mlm::correctSponsor((string) $m->id, (string) $b->id, 'one', 'admin-7'); // a -> b
        Mlm::correctSponsor((string) $m->id, (string) $a->id, 'two', 'admin-7'); // b -> a

        // the first row (a -> b) still exists and matches the row's state again
        expect(fn () => DB::table('mlm_members')->where('id', $m->id)->update(['sponsor_id' => $b->id]))
            ->toThrow(QueryException::class, 'sponsor_id is permanent');
        expect($m->fresh()->sponsor_id)->toBe($a->id);
    });

    it('refuses clearing a sponsor, which no audit row can name', function () {
        [, , $m] = correctionTrio();

        expect(fn () => DB::table('mlm_members')->where('id', $m->id)->update(['sponsor_id' => null]))
            ->toThrow(QueryException::class, 'sponsor_id is permanent');
    });

    it('refuses self-sponsorship even with an audit row for it', function () {
        [, , $m] = correctionTrio();
        rawCorrection($m->id, $m->sponsor_id, $m->id);

        expect(fn () => DB::table('mlm_members')->where('id', $m->id)->update(['sponsor_id' => $m->id]))
            ->toThrow(QueryException::class, 'cannot be the member itself');
    });

    it('lets through exactly the change the latest audit row names: the contract is "audited", not "made by this package"', function () {
        [$a, $b, $m] = correctionTrio();
        rawCorrection($m->id, $a->id, $b->id);

        DB::table('mlm_members')->where('id', $m->id)->update(['sponsor_id' => $b->id]);

        expect($m->fresh()->sponsor_id)->toBe($b->id);
        // ... and only once: the same row cannot carry it back
        expect(fn () => DB::table('mlm_members')->where('id', $m->id)->update(['sponsor_id' => $a->id]))
            ->toThrow(QueryException::class, 'sponsor_id is permanent');
    });
});

describe('the audit table is append-only', function () {
    it('refuses a raw update or delete of an audit row', function () {
        [, $b, $m] = correctionTrio();
        allowCorrections();
        Mlm::correctSponsor((string) $m->id, (string) $b->id, 'ticket 1', 'admin-7');

        expect(fn () => DB::table('mlm_sponsor_corrections')->update(['reason' => 'rewritten']))
            ->toThrow(QueryException::class, 'append-only');
        expect(fn () => DB::table('mlm_sponsor_corrections')->delete())
            ->toThrow(QueryException::class, 'append-only');
        expect(DB::table('mlm_sponsor_corrections')->value('reason'))->toBe('ticket 1');
    });

    it('offers no update or delete in the package API', function () {
        $methods = array_map('strtolower', array_merge(
            get_class_methods(EloquentAttributionStore::class),
            get_class_methods(FancyMlm\Laravel\MlmManager::class),
            get_class_methods(FancyMlm\Attribution\ReferralProgram::class),
        ));
        $writers = array_filter($methods, fn (string $m) => str_contains($m, 'correction')
            && (str_contains($m, 'update') || str_contains($m, 'delete') || str_contains($m, 'remove') || str_contains($m, 'prune')));

        expect($writers)->toBe([]);
    });
});
