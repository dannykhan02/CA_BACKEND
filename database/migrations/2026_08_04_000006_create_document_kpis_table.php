<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_kpis', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('value');
            // Parsed numeric form of `value` (e.g. "64.2M" -> 64200000).
            // Nullable: some AI-written values genuinely aren't numeric —
            // treat null as "not chartable", not zero.
            $table->decimal('value_numeric', 20, 4)->nullable();
            $table->string('unit')->nullable();
            $table->enum('trend', ['up', 'down', 'flat'])->nullable();
            $table->string('trend_value')->nullable();
            $table->timestamps();

            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_kpis');
    }
};
