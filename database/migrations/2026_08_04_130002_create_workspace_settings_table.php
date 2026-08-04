<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preferences live per-workspace, not per-user, so Organization workspaces
 * (v2) inherit the same shape without a redesign.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_settings', function (Blueprint $table) {
            $table->foreignUuid('workspace_id')->primary()->constrained()->cascadeOnDelete();

            $table->string('ai_provider')->default('anthropic');
            $table->string('theme')->default('system'); // 'light' | 'dark' | 'system'
            $table->string('language', 10)->default('en');
            $table->string('timezone')->default('UTC');

            $table->boolean('powerbi_enabled')->default(false);
            $table->boolean('ocr_enabled')->default(true);
            $table->boolean('handwriting_enabled')->default(false);

            $table->enum('default_classification', ['Public', 'Internal', 'Confidential', 'Restricted'])
                  ->default('Internal');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_settings');
    }
};
