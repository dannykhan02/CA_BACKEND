<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores the CURRENT AI-derived document-type result per document —
 * NOT to be confused with documents.classification, which is an unrelated
 * security/access-control field (Public/Internal/Confidential/Restricted).
 * This table answers "what kind of document is this" (compliance_report,
 * financial_report, etc.); documents.classification answers "who is
 * allowed to see it." The two must never be conflated.
 *
 * One row per document (idempotent updateOrCreate on document_id) — full
 * execution history, including re-runs and prompt-version changes, lives
 * in document_ai_runs, not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_type_classifications', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $table->string('document_type');
            $table->decimal('confidence', 4, 3);
            $table->text('reasoning');
            $table->string('prompt_version');
            $table->string('provider')->default('anthropic');
            $table->string('model')->nullable();
            $table->timestamps();

            $table->unique('document_id');
            $table->index(['workspace_id', 'document_type']);
        });

        // Controlled vocabulary, enforced at the DB level — mirrors the
        // documents_classification_check pattern already in use for the
        // (unrelated) security classification field. Prevents Claude from
        // inventing ad-hoc taxonomy variants (compliance-report vs
        // compliance_report vs regulatory_compliance_report).
        DB::statement("ALTER TABLE document_type_classifications ADD CONSTRAINT document_type_classifications_type_check
            CHECK (document_type IN (
                'compliance_report','financial_report','regulatory_filing','policy_document',
                'contract','correspondence','technical_report','meeting_minutes',
                'research_report','application_form','invoice','other'
            ))");

        DB::statement('ALTER TABLE document_type_classifications ADD CONSTRAINT document_type_classifications_confidence_check
            CHECK (confidence >= 0 AND confidence <= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_type_classifications');
    }
};