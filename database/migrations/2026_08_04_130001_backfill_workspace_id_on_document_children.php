<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // document_kpis, document_charts, document_page_flags all have
        // document_id directly.
        foreach (['document_kpis', 'document_charts', 'document_page_flags'] as $table) {
            DB::statement("
                UPDATE {$table} t
                SET workspace_id = d.workspace_id
                FROM documents d
                WHERE t.document_id = d.id
                  AND t.workspace_id IS NULL
            ");
        }

        // document_chart_points goes through document_charts.
        DB::statement("
            UPDATE document_chart_points p
            SET workspace_id = d.workspace_id
            FROM document_charts c
            JOIN documents d ON d.id = c.document_id
            WHERE p.document_chart_id = c.id
              AND p.workspace_id IS NULL
        ");
    }

    public function down(): void
    {
        // Deliberately irreversible — nulling workspace_id back out isn't
        // a meaningful inverse of a backfill.
    }
};
