<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email')->unique();
            $table->string('password');
            $table->string('full_name');
            $table->enum('role', ['Administrator', 'Reviewer', 'Analyst', 'Viewer'])
                  ->default('Viewer');
            $table->timestamp('email_verified_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('last_active_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->index('role');
            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
