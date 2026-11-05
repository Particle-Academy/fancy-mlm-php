<?php

declare(strict_types=1);

use FancyMlm\Attribution\Exceptions\SelfReferral;
use FancyMlm\Attribution\SessionHasher;
use FancyMlm\Laravel\MlmServiceProvider;
use FancyMlm\Attribution\Exceptions\SponsorImmutable;
use FancyMlm\Laravel\EloquentAttributionStore;
use FancyMlm\Laravel\Facades\Mlm;
use FancyMlm\Laravel\Models\Member as MemberModel;
use FancyMlm\Laravel\Models\ReferralScan;
use FancyMlm\Tests\Support\ReferralFixture;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function requestWithSession(?Store $session = null): Request
{
    $request = Request::create('/r/whatever', 'GET', server: [
        'REMOTE_ADDR' => '203.0.113.77',
        'HTTP_USER_AGENT' => 'FancyProbeAgent/9.9 (sentinel-ua)',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.23',
    ]);
    $session ??= new Store('test', new ArraySessionHandler(120));
    $session->start();
    $request->setLaravelSession($session);

    return $request;
}

/** Every row of every table, as one string: the "is it stored anywhere" probe. */
function wholeDatabase(): string
{
    $out = '';
    foreach (DB::select("select name from sqlite_master where type = 'table'") as $t) {
        $out .= json_encode(DB::table($t->name)->get()->all());
    }

    return $out;
}

it('creates the codes, scans and attributions tables with no column for an IP or user agent', function () {
    foreach (['mlm_referral_codes', 'mlm_referral_scans', 'mlm_attributions'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }

    $columns = array_map('strtolower', Schema::getColumnListing('mlm_referral_scans'));
    expect($columns)->not->toContain('ip')
        ->not->toContain('ip_address')
        ->not->toContain('user_agent')
        ->not->toContain('session_id');
    expect($columns)->toContain('session_hash');
});

it('records a scan from a request without storing the IP, user agent or raw session token anywhere', function () {
    $owner = MemberModel::create();
    $code = Mlm::issueCode((string) $owner->id, 'scope-1')->code;
    $request = requestWithSession();

    $result = Mlm::recordScanFromRequest($request, $code);

    expect($result->status)->toBe('recorded');
    $dump = wholeDatabase();
    $token = $request->session()->get('mlm.referral_session');
    expect($token)->toBeString()->not->toBe('');
    expect($dump)->not->toContain('203.0.113.77')
        ->not->toContain('198.51.100.23')
        ->not->toContain('sentinel-ua')
        ->not->toContain($token)
        ->not->toContain($request->session()->getId());
    expect(ReferralScan::query()->value('context'))->toBeNull();
});

it('stores host context only when the host passes it', function () {
    $owner = MemberModel::create();
    $code = Mlm::issueCode((string) $owner->id, 'scope-1')->code;

    Mlm::recordScanFromRequest(requestWithSession(), $code, ['placement' => 'poster-3']);

    expect(json_decode((string) ReferralScan::query()->value('context'), true))->toBe(['placement' => 'poster-3']);
});

it('attributes a signup across a session id regeneration (login migrates the session id)', function () {
    $owner = MemberModel::create();
    $code = Mlm::issueCode((string) $owner->id, 'scope-1')->code;
    $request = requestWithSession();
    Mlm::recordScanFromRequest($request, $code);

    $request->session()->migrate(true); // what Auth::login() does
    $new = MemberModel::create();
    $decision = Mlm::attributeSignupFromRequest($request, (string) $new->id);

    expect($decision->attributed)->toBeTrue();
    expect($new->fresh()->sponsor_id)->toBe($owner->id);
    expect(DB::table('mlm_attributions')->where('member_id', $new->id)->value('code'))->toBe($code);
});

it('deduplicates refreshes from one session within the window', function () {
    $owner = MemberModel::create();
    $code = Mlm::issueCode((string) $owner->id, 'scope-1')->code;
    $request = requestWithSession();

    $statuses = array_map(fn () => Mlm::recordScanFromRequest($request, $code)->status, range(1, 5));

    expect($statuses)->toBe(['recorded', 'duplicate', 'duplicate', 'duplicate', 'duplicate']);
    expect(ReferralScan::query()->count())->toBe(1);
});

describe('the session hash key', function () {
    it('derives a sub-key from APP_KEY rather than using the app key itself', function () {
        $derived = MlmServiceProvider::sessionKey('', 'base64:'.base64_encode('app-secret'));

        expect($derived)->toBe(hash_hmac('sha256', 'fancy-mlm/session-hash/v1', 'app-secret'));
        expect($derived)->not->toBe('app-secret');
    });

    it('prefers an explicit key', function () {
        expect(MlmServiceProvider::sessionKey('explicit', 'base64:'.base64_encode('app-secret')))->toBe('explicit');
    });

    it('refuses to hash with no key at all', function () {
        config(['app.key' => '', 'mlm.attribution.session_key' => null]);

        app(SessionHasher::class);
    })->throws(RuntimeException::class, 'never unkeyed');

    it('hashes the session token under the configured key', function () {
        config(['mlm.attribution.session_key' => 'configured-key']);
        $owner = MemberModel::create();
        $code = Mlm::issueCode((string) $owner->id, 'scope-1')->code;
        $request = requestWithSession();

        $scan = Mlm::recordScanFromRequest($request, $code)->scan;

        expect($scan->sessionHash)->toBe(hash_hmac('sha256', $request->session()->get('mlm.referral_session'), 'configured-key'));
    });
});

describe('the permanent sponsor', function () {
    it('lets the model set a sponsor once', function () {
        $a = MemberModel::create();
        $m = MemberModel::create();

        $m->update(['sponsor_id' => $a->id]);

        expect($m->fresh()->sponsor_id)->toBe($a->id);
    });

    it('fails loudly when the model changes it', function () {
        $a = MemberModel::create();
        $b = MemberModel::create();
        $m = MemberModel::create(['sponsor_id' => $a->id]);

        $m->update(['sponsor_id' => $b->id]);
    })->throws(SponsorImmutable::class);

    it('fails loudly when the model clears it', function () {
        $a = MemberModel::create();
        $m = MemberModel::create(['sponsor_id' => $a->id]);

        $m->update(['sponsor_id' => null]);
    })->throws(SponsorImmutable::class);

    it('fails loudly when the model sponsors a member by itself', function () {
        $m = MemberModel::create();

        $m->update(['sponsor_id' => $m->id]);
    })->throws(SelfReferral::class);

    it('is enforced by the database when Eloquent is bypassed', function () {
        $a = MemberModel::create();
        $b = MemberModel::create();
        $m = MemberModel::create(['sponsor_id' => $a->id]);

        expect(fn () => DB::table('mlm_members')->where('id', $m->id)->update(['sponsor_id' => $b->id]))
            ->toThrow(QueryException::class, 'sponsor_id is permanent');
        expect(fn () => DB::table('mlm_members')->where('id', $m->id)->update(['sponsor_id' => null]))
            ->toThrow(QueryException::class, 'sponsor_id is permanent');
        expect(MemberModel::query()->find($m->id)->sponsor_id)->toBe($a->id);
    });

    it('lets the database set a null sponsor once, and refuses self-sponsorship there too', function () {
        $a = MemberModel::create();
        $m = MemberModel::create();

        DB::table('mlm_members')->where('id', $m->id)->update(['sponsor_id' => $a->id]);
        expect(MemberModel::query()->find($m->id)->sponsor_id)->toBe($a->id);

        expect(fn () => DB::table('mlm_members')->where('id', $a->id)->update(['sponsor_id' => $a->id]))
            ->toThrow(QueryException::class, 'sponsor_id');
    });

    it('still lets other columns change', function () {
        $a = MemberModel::create();
        $m = MemberModel::create(['sponsor_id' => $a->id]);

        DB::table('mlm_members')->where('id', $m->id)->update(['tier' => 'gold']);
        $m->fresh()->update(['active' => false]);

        expect($m->fresh()->tier)->toBe('gold');
    });

    it('is refused by the Eloquent store itself, as one conditional update', function () {
        $a = MemberModel::create();
        $b = MemberModel::create();
        $m = MemberModel::create();
        $store = app(EloquentAttributionStore::class);
        $store->assignSponsor((string) $m->id, (string) $a->id);
        $store->assignSponsor((string) $m->id, (string) $a->id); // same sponsor again: no-op

        expect(fn () => $store->assignSponsor((string) $m->id, (string) $b->id))->toThrow(SponsorImmutable::class);
        expect($m->fresh()->sponsor_id)->toBe($a->id);
    });

    it('fails loudly through the facade', function () {
        $a = MemberModel::create();
        $b = MemberModel::create();
        $m = MemberModel::create();
        Mlm::assignSponsor((string) $m->id, (string) $a->id);

        Mlm::assignSponsor((string) $m->id, (string) $b->id);
    })->throws(SponsorImmutable::class);
});

describe('stats', function () {
    it('passes the shared stats rows against the database', function (array $case) {
        $rows = ReferralFixture::run($case, fn () => app(EloquentAttributionStore::class));

        expect($rows)->toEqual($case['expected']);
    })->with(function (): array {
        $rows = [];
        foreach (ReferralFixture::load()['cases'] as $case) {
            if ($case['fn'] === 'stats') {
                $rows[$case['id']] = [$case];
            }
        }

        return $rows;
    });

    it('answers in two queries however many codes there are (no N+1)', function () {
        $owner = MemberModel::create();
        foreach (range(1, 25) as $i) {
            $code = Mlm::issueCode((string) $owner->id, 'scope-'.($i % 3))->code;
            Mlm::recordScan($code, 'visitor-'.$i);
        }

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $rows = Mlm::stats('code', now()->subDay()->toDateTimeImmutable(), now()->addDay()->toDateTimeImmutable());

        expect($rows)->toHaveCount(25);
        expect($queries)->toBe(2);
    });
});

describe('retention', function () {
    it('prunes old scans with the artisan command', function () {
        config(['mlm.attribution.retention_days' => 60, 'mlm.attribution.window_days' => 30]);
        $owner = MemberModel::create();
        $code = Mlm::issueCode((string) $owner->id, 'scope-1')->code;
        Mlm::recordScan($code, 'old', [], now()->subDays(90)->toDateTimeImmutable());
        Mlm::recordScan($code, 'recent', [], now()->subDays(5)->toDateTimeImmutable());

        $this->artisan('mlm:prune-scans')->assertExitCode(0);

        expect(ReferralScan::query()->count())->toBe(1);
    });

    it('refuses to prune inside the attribution window', function () {
        config(['mlm.attribution.retention_days' => 7, 'mlm.attribution.window_days' => 30]);

        $this->artisan('mlm:prune-scans')->assertExitCode(1);
    });
});
