<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('documents_remaining')->default(0);
            $table->unsignedInteger('documents_purchased_total')->default(0);
            $table->timestamps();
        });

        DB::table('workspace_credits')->insertUsing(
            ['workspace_id', 'created_at', 'updated_at'],
            DB::table('workspaces')->select('id', 'created_at', 'updated_at')
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_credits');
    }
};
