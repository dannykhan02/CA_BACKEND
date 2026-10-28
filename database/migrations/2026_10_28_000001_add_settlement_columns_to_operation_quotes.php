<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a quote was finally charged. Additive and nullable: a quote settled before this migration keeps null
 * and is read as "charged its quoted credits". settled_at already exists on operation_quotes and keeps its meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operation_quotes', function (Blueprint $t) {
            $t->unsignedInteger('charged_credits')->nullable();
            $t->unsignedInteger('released_credits')->nullable();
            $t->decimal('actual_cost_usd', 12, 6)->nullable();
            $t->string('settlement_basis')->nullable(); // quoted | actual | quoted_fallback
        });
    }

    public function down(): void
    {
        Schema::table('operation_quotes', fn (Blueprint $t) => $t->dropColumn(['charged_credits', 'released_credits', 'actual_cost_usd', 'settlement_basis']));
    }
};
