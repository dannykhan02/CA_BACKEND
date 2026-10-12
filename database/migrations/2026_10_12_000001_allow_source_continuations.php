<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE source_relationships DROP CONSTRAINT source_relation_type_check');
        DB::statement("ALTER TABLE source_relationships ADD CONSTRAINT source_relation_type_check CHECK (type IN ('contained_by', 'row_header', 'column_header', 'table_parent', 'section_parent', 'caption', 'footnote', 'continuation'))");
        DB::statement('ALTER TABLE source_anchors DROP CONSTRAINT source_anchor_kind_check');
        DB::statement("ALTER TABLE source_anchors ADD CONSTRAINT source_anchor_kind_check CHECK (kind IN ('text_span', 'paragraph', 'table', 'table_row', 'table_column', 'cell', 'row_header', 'column_header', 'section_heading', 'caption', 'footnote', 'worksheet_cell', 'ocr_region'))");
    }

    public function down(): void
    {
        if (DB::table('source_relationships')->where('type', 'continuation')->exists()) {
            throw new LogicException('Remove continuation proof before reverting its schema support.');
        }
        if (DB::table('source_anchors')->whereIn('kind', ['table_row', 'table_column'])->exists()) {
            throw new LogicException('Remove row and column anchors before reverting their schema support.');
        }
        DB::statement('ALTER TABLE source_relationships DROP CONSTRAINT source_relation_type_check');
        DB::statement("ALTER TABLE source_relationships ADD CONSTRAINT source_relation_type_check CHECK (type IN ('contained_by', 'row_header', 'column_header', 'table_parent', 'section_parent', 'caption', 'footnote'))");
        DB::statement('ALTER TABLE source_anchors DROP CONSTRAINT source_anchor_kind_check');
        DB::statement("ALTER TABLE source_anchors ADD CONSTRAINT source_anchor_kind_check CHECK (kind IN ('text_span', 'paragraph', 'table', 'cell', 'row_header', 'column_header', 'section_heading', 'caption', 'footnote', 'worksheet_cell', 'ocr_region'))");
    }
};
