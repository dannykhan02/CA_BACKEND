<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Power BI never touches live app tables directly — only these two views.
 * Both hard-exclude Restricted documents regardless of any future
 * auto_extract_classifications config change, since a BI dashboard is a
 * much broader-audience surface than the app's own classification policy.
 * See docs/DECISIONS.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE VIEW power_bi_kpis AS
            SELECT
                d.id AS document_id,
                d.name AS document_name,
                d.classification,
                d.year,
                d.created_at AS document_uploaded_at,
                k.label,
                k.value AS value_display,
                k.value_numeric,
                k.unit,
                k.trend,
                k.trend_value
            FROM document_kpis k
            JOIN documents d ON d.id = k.document_id
            WHERE d.classification != 'Restricted'
              AND d.status = 'Ready'
        ");

        DB::statement("
            CREATE VIEW power_bi_chart_points AS
            SELECT
                d.id AS document_id,
                d.name AS document_name,
                d.classification,
                d.year,
                c.id AS chart_id,
                c.type AS chart_type,
                c.title AS chart_title,
                p.label,
                p.value,
                p.sort_order
            FROM document_chart_points p
            JOIN document_charts c ON c.id = p.document_chart_id
            JOIN documents d ON d.id = c.document_id
            WHERE d.classification != 'Restricted'
              AND d.status = 'Ready'
        ");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS power_bi_kpis');
        DB::statement('DROP VIEW IF EXISTS power_bi_chart_points');
    }
};
