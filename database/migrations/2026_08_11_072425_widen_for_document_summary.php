<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE processing_jobs DROP CONSTRAINT processing_jobs_stage_check');
        DB::statement("ALTER TABLE processing_jobs ADD CONSTRAINT processing_jobs_stage_check
            CHECK (stage IN ('virus_scan','file_detection','ocr_check','extract','normalize','chunk','ai_analysis','kpis','charts','power_bi','document_type','entities','risks','deadlines','document_summary'))");

        DB::statement('ALTER TABLE document_ai_runs DROP CONSTRAINT document_ai_runs_purpose_check');
        DB::statement("ALTER TABLE document_ai_runs ADD CONSTRAINT document_ai_runs_purpose_check
            CHECK (purpose IN ('ocr','insights','document_type','entities','risks','deadlines','document_summary'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE processing_jobs DROP CONSTRAINT processing_jobs_stage_check');
        DB::statement("ALTER TABLE processing_jobs ADD CONSTRAINT processing_jobs_stage_check
            CHECK (stage IN ('virus_scan','file_detection','ocr_check','extract','normalize','chunk','ai_analysis','kpis','charts','power_bi','document_type','entities','risks','deadlines'))");

        DB::statement('ALTER TABLE document_ai_runs DROP CONSTRAINT document_ai_runs_purpose_check');
        DB::statement("ALTER TABLE document_ai_runs ADD CONSTRAINT document_ai_runs_purpose_check
            CHECK (purpose IN ('ocr','insights','document_type','entities','risks','deadlines'))");
    }
};
