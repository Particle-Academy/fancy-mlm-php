<?php

use FancyMlm\Laravel\Database\SponsorGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Replaces the 0.4.0 sponsor guard with one that admits exactly one kind of
 * change to a set sponsor_id: the one the member's LATEST row in
 * mlm_sponsor_corrections names, from the current sponsor to the new one.
 * Every other change is refused as before: a query-builder update, a raw
 * statement, another app sharing the table. See SponsorGuard for why "latest
 * row" closes replay.
 *
 * Down restores the 0.4.0 guard exactly. Both directions are skipped when
 * `mlm.attribution.database_guard` is false, matching the 0.4.0 migration.
 *
 * SQLite is tested. The MySQL/MariaDB and PostgreSQL SQL is written to the same
 * rule but not run by this package's suite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! config('mlm.attribution.database_guard', true)) {
            return;
        }
        $members = (string) config('mlm.tables.members', 'mlm_members');

        SponsorGuard::uninstall(DB::connection(), $members);
        SponsorGuard::install(DB::connection(), $members, (string) config('mlm.tables.sponsor_corrections', 'mlm_sponsor_corrections'));
    }

    public function down(): void
    {
        $members = (string) config('mlm.tables.members', 'mlm_members');

        SponsorGuard::uninstall(DB::connection(), $members);
        if (config('mlm.attribution.database_guard', true)) {
            SponsorGuard::install(DB::connection(), $members);
        }
    }
};
