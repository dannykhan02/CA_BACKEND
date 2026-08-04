<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_chart_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_chart_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->decimal('value', 20, 4);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index('document_chart_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chart_points');
    }
};