<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Persistent home for native/OCR extracted text. Previously this only
     * ever lived in a 2-hour cache entry (document:{id}:extracted_text),
     * which meant any downstream job running after the TTL expired —
     * embeddings, and presumably AI insights — silently found nothing.
     * Additive only: nullable, no backfill of historical rows here since
     * their source text is genuinely gone (was never persisted).
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->longText('extracted_text')->nullable()->after('insights');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('extracted_text');
        });
    }
};