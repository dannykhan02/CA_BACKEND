<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS documents_workspace_file_hash_unique');
        DB::statement('CREATE UNIQUE INDEX documents_workspace_file_hash_unique ON documents (workspace_id, file_hash) WHERE file_hash IS NOT NULL AND deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS documents_workspace_file_hash_unique');
        DB::statement('CREATE UNIQUE INDEX documents_workspace_file_hash_unique ON documents (workspace_id, file_hash) WHERE file_hash IS NOT NULL');
    }
};
