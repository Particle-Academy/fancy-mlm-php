<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create((string) config('mlm.tables.members', 'mlm_members'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('sponsor_id')->nullable()->index();   // enroller tree
            $table->unsignedBigInteger('placement_id')->nullable()->index(); // binary/matrix placement (future)
            $table->string('tier')->default('default')->index();
            $table->boolean('active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('mlm.tables.members', 'mlm_members'));
    }
};
