<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
    }

    public function down(): void
    {
        // Deliberately not dropping — dropping the extension would cascade-drop
        // any vector columns that depend on it, which is destructive by nature.
    }
};