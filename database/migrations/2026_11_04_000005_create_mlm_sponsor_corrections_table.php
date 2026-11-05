<?php

use FancyMlm\Laravel\Database\SponsorGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The audit trail for `Mlm::correctSponsor()`: one row per corrected sponsor,
 * naming the member, the sponsor before and after, the reason and the actor.
 *
 * APPEND-ONLY. The package offers no way to update or delete a row, and where
 * the database guard is on (`mlm.attribution.database_guard`, the default) a
 * trigger refuses UPDATE and DELETE as well. That matters beyond tidiness: the
 * sponsor guard admits a change only when the member's LATEST row names it, so
 * a deleted or edited row could otherwise re-arm an older one.
 *
 * `reason` is free text written by your staff. If it can hold personal data,
 * that is yours to account for: these rows are kept forever by design.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = (string) config('mlm.tables.sponsor_corrections', 'mlm_sponsor_corrections');

        Schema::create($table, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('member_id');
            $table->unsignedBigInteger('from_sponsor_id');
            $table->unsignedBigInteger('to_sponsor_id');
            $table->string('reason', 1000);
            $table->string('actor_id', 191);
            $table->dateTime('corrected_at');

            $table->index(['member_id', 'id']);
            $table->index('actor_id');
            $table->index('corrected_at');
        });

        if (config('mlm.attribution.database_guard', true)) {
            SponsorGuard::installAppendOnly(DB::connection(), $table);
        }
    }

    public function down(): void
    {
        $table = (string) config('mlm.tables.sponsor_corrections', 'mlm_sponsor_corrections');

        if (Schema::hasTable($table)) {
            SponsorGuard::uninstallAppendOnly(DB::connection(), $table);
        }
        Schema::dropIfExists($table);
    }
};
