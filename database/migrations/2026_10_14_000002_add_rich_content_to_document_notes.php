<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_notes', function (Blueprint $table): void {
            $table->jsonb('content')->nullable();
            $table->string('content_format', 20)->default('plain_text');
        });
        DB::statement("ALTER TABLE document_notes ADD CONSTRAINT document_notes_content_format_check CHECK
            ((content_format = 'plain_text' AND content IS NULL) OR
             (content_format = 'tiptap_v1' AND content IS NOT NULL AND jsonb_typeof(content) = 'object'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE document_notes DROP CONSTRAINT document_notes_content_format_check');
        Schema::table('document_notes', function (Blueprint $table): void {
            $table->dropColumn(['content', 'content_format']);
        });
    }
};
