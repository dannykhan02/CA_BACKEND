<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Why a settlement fell back to the quoted amount (no_recorded_cost, unknown_run_cost, ...), so it can be counted. Nullable; null = no fallback. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operation_quotes', fn (Blueprint $t) => $t->string('settlement_reason')->nullable());
    }

    public function down(): void
    {
        Schema::table('operation_quotes', fn (Blueprint $t) => $t->dropColumn('settlement_reason'));
    }
};
