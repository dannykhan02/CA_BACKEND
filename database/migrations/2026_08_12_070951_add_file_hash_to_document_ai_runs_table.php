<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enables the Day 8 Batch 3 reprocessing guard: each intelligence job
 * compares $document->file_hash against the file_hash recorded on the
 * most recent document_ai_runs row for that purpose, and skips the Claude
 * call entirely when they match (unless forceReprocess is set).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_ai_runs', function (Blueprint $table) {
            $table->string('file_hash', 64)->nullable()->after('document_id');
            $table->index(['document_id', 'purpose', 'file_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('document_ai_runs', function (Blueprint $table) {
            $table->dropIndex(['document_id', 'purpose', 'file_hash']);
            $table->dropColumn('file_hash');
        });
    }
};
