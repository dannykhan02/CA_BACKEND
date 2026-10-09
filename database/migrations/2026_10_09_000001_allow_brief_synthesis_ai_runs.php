<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * B2 narrative synthesis is its own billable purpose, so its runs are distinguishable in
 * docintel:ai-usage-report and in any per-purpose spend query. Additive and reversible; no backfill.
 */
return new class extends Migration
{
    private const PREVIOUS = "'ocr','insights','document_type','entities','risks','deadlines','document_summary','document_qa','chart_vision','document_comparison','kpi_identity'";

    public function up(): void
    {
        DB::statement('ALTER TABLE document_ai_runs DROP CONSTRAINT document_ai_runs_purpose_check');
        DB::statement('ALTER TABLE document_ai_runs ADD CONSTRAINT document_ai_runs_purpose_check CHECK (purpose IN ('.self::PREVIOUS.",'brief_synthesis'))");
    }

    public function down(): void
    {
        if (DB::table('document_ai_runs')->where('purpose', 'brief_synthesis')->exists()) {
            throw new RuntimeException('Brief narrative AI audit records exist; retain their history before rolling back.');
        }
        DB::statement('ALTER TABLE document_ai_runs DROP CONSTRAINT document_ai_runs_purpose_check');
        DB::statement('ALTER TABLE document_ai_runs ADD CONSTRAINT document_ai_runs_purpose_check CHECK (purpose IN ('.self::PREVIOUS.'))');
    }
};
