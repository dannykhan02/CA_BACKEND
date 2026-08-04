<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_kpis', function (Blueprint $table) {
            // Parsed numeric form of `value` (e.g. "64.2M" -> 64200000,
            // "KES 312" -> 312, "97.13" -> 97.13). Nullable because some
            // AI-written values genuinely aren't numeric (e.g. free text
            // observations) — Power BI/reporting consumers should treat
            // null here as "not chartable", not as zero.
            $table->decimal('value_numeric', 20, 4)->nullable()->after('value');
        });
    }

    public function down(): void
    {
        Schema::table('document_kpis', function (Blueprint $table) {
            $table->dropColumn('value_numeric');
        });
    }
};