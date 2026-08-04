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
            $table->string('name')->nullable(); // null for Personal
            $table->timestamps();
        });

        DB::statement(
            "ALTER TABLE workspaces ADD CONSTRAINT workspaces_type_check CHECK (type IN ('Personal','Organization'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
