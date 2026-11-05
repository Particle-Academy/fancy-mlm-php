<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create((string) config('mlm.tables.referral_codes', 'mlm_referral_codes'), function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->unsignedBigInteger('member_id');
            $table->string('scope', 191);              // host-defined, opaque
            $table->string('status', 16)->default('active');
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'scope']);
            $table->index('scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('mlm.tables.referral_codes', 'mlm_referral_codes'));
    }
};
