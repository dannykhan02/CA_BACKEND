<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces reading provider/model/keys from .env. In v1 there's one row
 * per workspace (usually one workspace per user); v2 organizations just
 * get their own row, no schema change needed.
 *
 * api_key_encrypted must ONLY ever be written/read through the model's
 * `encrypted` cast (App\Models\WorkspaceAiConfig -> protected $casts =
 * ['api_key_encrypted' => 'encrypted']) — never store or log the plaintext
 * key anywhere else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_ai_configs', function (Blueprint $table) {
            $table->foreignUuid('workspace_id')->primary()->constrained()->cascadeOnDelete();

            $table->string('provider')->default('anthropic');
            $table->string('model')->default('claude-sonnet-4-6');
            $table->decimal('temperature', 3, 2)->default(0.20);
            $table->text('api_key_encrypted')->nullable();
            $table->unsignedInteger('max_tokens')->default(4096);
            $table->boolean('vision_enabled')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_ai_configs');
    }
};
