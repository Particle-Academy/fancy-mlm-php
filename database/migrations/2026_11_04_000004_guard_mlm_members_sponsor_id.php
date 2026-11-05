<?php

use FancyMlm\Laravel\Database\SponsorGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Makes mlm_members.sponsor_id permanent AT THE DATABASE, so a query-builder
 * update, a raw statement or another app sharing the table cannot change it
 * behind the model guard. A BEFORE UPDATE trigger refuses:
 *
 *   - changing or clearing a sponsor_id that is already set
 *   - setting sponsor_id to the row's own id
 *
 * SQLite: trigger with RAISE(ABORT). MySQL/MariaDB: trigger with SIGNAL
 * SQLSTATE '45000'. PostgreSQL: a plpgsql function plus trigger (PG 11+).
 * Any other driver: skipped, and the model guard is the only guard.
 *
 * Set `mlm.attribution.database_guard` to false if your database user cannot
 * create triggers (MySQL with binary logging may also need
 * log_bin_trust_function_creators). The code paths still refuse a change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! config('mlm.attribution.database_guard', true)) {
            return;
        }

        SponsorGuard::install(DB::connection(), (string) config('mlm.tables.members', 'mlm_members'));
    }

    public function down(): void
    {
        SponsorGuard::uninstall(DB::connection(), (string) config('mlm.tables.members', 'mlm_members'));
    }
};
