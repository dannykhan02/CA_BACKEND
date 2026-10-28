<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The hold can be smaller than the quote (ai_credits.admission_min_ratio), and settlement can cost more than the hold.
 * Both are recorded on the quote. Additive and nullable: null reads as "held the whole quote" / "nothing absorbed".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operation_quotes', function (Blueprint $t) {
            $t->unsignedInteger('held_credits')->nullable();
            $t->unsignedInteger('absorbed_credits')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('operation_quotes', fn (Blueprint $t) => $t->dropColumn(['held_credits', 'absorbed_credits']));
    }
};
