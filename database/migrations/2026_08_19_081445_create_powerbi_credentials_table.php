<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maps each Postgres LOGIN role used by a Power BI reporting credential to
 * exactly one workspace_id. This is the join target for the RLS policies
 * added in the next migration — a role with no row here is denied every
 * row (fail closed), not granted everything by default.
 *
 * One credential per client/workspace, going forward, via
 * `php artisan powerbi:create-reader {workspace}` rather than editing this
 * table by hand — see App\Console\Commands\CreatePowerBiReader.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('powerbi_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('db_role')->unique();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('powerbi_credentials');
    }
};