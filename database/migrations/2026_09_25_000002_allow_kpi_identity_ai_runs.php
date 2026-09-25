<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PREVIOUS = "'ocr','insights','document_type','entities','risks','deadlines','document_summary','document_qa','chart_vision','document_comparison'";

    public function up(): void
    {
        DB::statement('ALTER TABLE document_ai_runs DROP CONSTRAINT document_ai_runs_purpose_check');
        DB::statement('ALTER TABLE document_ai_runs ADD CONSTRAINT document_ai_runs_purpose_check CHECK (purpose IN ('.self::PREVIOUS.",'kpi_identity'))");
    }

    public function down(): void
    {
        if (DB::table('document_ai_runs')->where('purpose', 'kpi_identity')->exists()) {
            throw new RuntimeException('KPI identity AI audit records exist; retain their history before rolling back.');
        }
        DB::statement('ALTER TABLE document_ai_runs DROP CONSTRAINT document_ai_runs_purpose_check');
        DB::statement('ALTER TABLE document_ai_runs ADD CONSTRAINT document_ai_runs_purpose_check CHECK (purpose IN ('.self::PREVIOUS.'))');
    }
};
