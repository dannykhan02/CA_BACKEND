// database/migrations/2026_08_04_000001_create_workspaces_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type'); // 'Personal' | 'Organization'
            $table->string('name')->nullable(); // null for Personal, required once Organization ships
            $table->timestamps();
        });

        // Same enforcement pattern as documents_classification_check —
        // the enum lives at the DB layer, not just in application code.
        DB::statement(
            "ALTER TABLE workspaces ADD CONSTRAINT workspaces_type_check CHECK (type IN ('Personal','Organization'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};