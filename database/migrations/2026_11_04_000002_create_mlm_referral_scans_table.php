<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * One row per recorded scan or click. Deliberately has NO column for an IP
 * address, a user agent or a session id: `session_hash` is an HMAC under a
 * secret key, and `context` holds only what the host passed explicitly.
 *
 * `code`, `member_id` and `scope` are copied from the code at scan time so the
 * stats queries group without a join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create((string) config('mlm.tables.referral_scans', 'mlm_referral_scans'), function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64);
            $table->unsignedBigInteger('member_id');
            $table->string('scope', 191);
            $table->char('session_hash', 64);
            $table->json('context')->nullable();
            $table->dateTime('scanned_at');

            $table->index(['code', 'session_hash', 'scanned_at']); // dedupe lookup
            $table->index(['session_hash', 'scanned_at']);         // attribution candidates
            $table->index(['code', 'scanned_at']);                 // stats by code
            $table->index(['scope', 'scanned_at']);                // stats by scope
            $table->index(['member_id', 'scanned_at']);            // stats by member
            $table->index('scanned_at');                           // retention prune
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('mlm.tables.referral_scans', 'mlm_referral_scans'));
    }
};
