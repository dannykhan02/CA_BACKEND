<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_call_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('operation_quote_id')->constrained('operation_quotes')->cascadeOnDelete();
            $table->string('purpose', 64);
            $table->string('status', 24); // reserved, dispatched, settled, uncertain, released
            $table->unsignedBigInteger('reserved_microusd');
            $table->unsignedBigInteger('settled_microusd')->nullable();
            $table->timestamps();
            $table->index(['operation_quote_id', 'status']);
        });

        Schema::table('document_ai_runs', function (Blueprint $table) {
            $table->uuid('provider_attempt_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('document_ai_runs', fn (Blueprint $table) => $table->dropColumn('provider_attempt_id'));
        Schema::dropIfExists('provider_call_attempts');
    }
};
