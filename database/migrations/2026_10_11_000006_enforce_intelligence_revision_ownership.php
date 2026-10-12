<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLES = [
        'document_kpis', 'document_charts', 'document_entities', 'document_risks',
        'document_deadlines', 'document_type_classifications', 'document_intelligence_summaries',
    ];

    public function up(): void
    {
        DB::statement("ALTER TABLE document_intelligence_revisions ADD CONSTRAINT intelligence_revision_state_check
            CHECK (state IN ('candidate', 'ready', 'published', 'failed', 'superseded', 'cancelled') AND generation > 0)");
        DB::statement('CREATE UNIQUE INDEX intelligence_revision_pipeline_unique
            ON document_intelligence_revisions (document_id, pipeline_key) WHERE pipeline_key IS NOT NULL');
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_revision_owner_fk
                FOREIGN KEY (intelligence_revision_id, document_id, workspace_id)
                REFERENCES document_intelligence_revisions (id, document_id, workspace_id)");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_revision_workspace_check
                CHECK (intelligence_revision_id IS NULL OR workspace_id IS NOT NULL)");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_revision_workspace_check");
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_revision_owner_fk");
        }
        DB::statement('DROP INDEX intelligence_revision_pipeline_unique');
        DB::statement('ALTER TABLE document_intelligence_revisions DROP CONSTRAINT intelligence_revision_state_check');
    }
};
