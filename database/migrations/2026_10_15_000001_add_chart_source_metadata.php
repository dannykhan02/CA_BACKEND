<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_charts', function (Blueprint $table) {
            // Nullable by design: historical chart rows contain no trustworthy source link.
            $table->jsonb('source_metadata')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('document_charts', fn (Blueprint $table) => $table->dropColumn('source_metadata'));
    }
};
