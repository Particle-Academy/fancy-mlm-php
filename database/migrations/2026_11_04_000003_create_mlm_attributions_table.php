<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Which scan made each member's sponsor. `member_id` is UNIQUE: a member is
 * attributed once, ever, and the database refuses a second row. `scan_id` is
 * kept without a foreign key so pruning old scans never removes a signup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create((string) config('mlm.tables.attributions', 'mlm_attributions'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('member_id')->unique();
            $table->unsignedBigInteger('sponsor_member_id');
            $table->string('code', 64);
            $table->string('scope', 191);
            $table->unsignedBigInteger('scan_id')->nullable();
            $table->string('model', 16);
            $table->dateTime('attributed_at');

            $table->index(['code', 'attributed_at']);
            $table->index(['scope', 'attributed_at']);
            $table->index(['sponsor_member_id', 'attributed_at']);
            $table->index('attributed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('mlm.tables.attributions', 'mlm_attributions'));
    }
};
