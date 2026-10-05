<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_chunks', function (Blueprint $table) {
            // Existing reserved_cost values remain conservative commitments on upgrade.
            $table->jsonb('cost_accounting')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('document_chunks', fn (Blueprint $table) => $table->dropColumn('cost_accounting'));
    }
};
