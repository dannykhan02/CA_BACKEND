<?php

use Database\Seeders\DocumentComparisonPromptSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Existing installations may run migrations without DatabaseSeeder.
        // firstOrCreate leaves an active, customized prompt untouched.
        (new DocumentComparisonPromptSeeder)->run();
    }

    public function down(): void
    {
        // Prompt versions may already have been used by comparison audit runs.
    }
};
