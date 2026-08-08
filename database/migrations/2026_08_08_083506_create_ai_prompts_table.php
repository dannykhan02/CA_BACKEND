<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_prompts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('version');
            $table->string('provider')->default('anthropic');
            $table->string('model')->nullable();
            $table->decimal('temperature', 3, 2)->nullable();
            $table->text('system_prompt')->nullable();
            $table->longText('template');
            $table->boolean('active')->default(false);
            $table->timestamps();

            $table->unique(['name', 'version']);
        });

        // Structural guarantee, not just app-level convention: the DB
        // itself refuses a second active row for the same prompt name,
        // even if a future Tinker session or migration bypasses AiPrompt::activate().
        DB::statement('CREATE UNIQUE INDEX ai_prompts_one_active_per_name ON ai_prompts (name) WHERE active = true');
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_prompts');
    }
};