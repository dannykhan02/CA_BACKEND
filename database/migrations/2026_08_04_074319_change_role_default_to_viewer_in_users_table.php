<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users.role defaulted to 'Owner', but AuthController::signup() and
 * google() both hardcode 'Viewer' explicitly, and DocumentPolicy's
 * classification matrix doesn't recognize 'Owner' as a role at all. The
 * 'Owner' default was dead code — harmless until something creates a User
 * without specifying role (a factory, a seeder, tinker), producing an
 * account invisible to every policy check.
 *
 * Aligning the schema default to match actual application behavior rather
 * than keeping a default the app itself never produces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('Viewer')->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('Owner')->change();
        });
    }
};