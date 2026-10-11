<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_anchors', function (Blueprint $table): void {
            $table->jsonb('navigation_metadata')->nullable();
        });

        Schema::table('document_evidence', function (Blueprint $table): void {
            $table->index(['workspace_id', 'document_id', 'source_id'], 'document_evidence_source_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('document_evidence', function (Blueprint $table): void {
            $table->dropIndex('document_evidence_source_lookup');
        });
        Schema::table('source_anchors', function (Blueprint $table): void {
            $table->dropColumn('navigation_metadata');
        });
    }
};
