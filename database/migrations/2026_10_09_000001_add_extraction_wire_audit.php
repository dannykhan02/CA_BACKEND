<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_ai_runs', function (Blueprint $table) {
            foreach (['wire_format_version', 'compact_codec_version'] as $name) $table->string($name, 48)->nullable();
            foreach (['raw_provider_response_hash', 'expanded_canonical_response_hash', 'provider_schema_hash',
                'canonical_schema_hash', 'prompt_hash', 'request_body_hash', 'source_hash', 'span_hash'] as $name) {
                $table->string($name, 64)->nullable();
            }
            $table->text('raw_provider_response')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('document_ai_runs', fn (Blueprint $table) => $table->dropColumn([
            'wire_format_version', 'compact_codec_version', 'raw_provider_response_hash',
            'expanded_canonical_response_hash', 'provider_schema_hash', 'canonical_schema_hash',
            'prompt_hash', 'request_body_hash', 'source_hash', 'span_hash', 'raw_provider_response',
        ]));
    }
};
