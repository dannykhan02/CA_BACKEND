<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The existing attempt ledger also holds internal provider cost for operations with
        // no customer quote. These fields are nullable so old quote-backed workers still write.
        Schema::table('provider_call_attempts', function (Blueprint $table) {
            $table->uuid('workspace_id')->nullable();
            $table->string('scope_key', 191)->nullable();
            $table->unsignedBigInteger('scope_cap_microusd')->nullable();
            $table->index(['workspace_id', 'scope_key', 'status'], 'provider_attempts_internal_scope_index');
        });
        DB::statement('ALTER TABLE provider_call_attempts ALTER COLUMN operation_quote_id DROP NOT NULL');
        DB::statement('ALTER TABLE provider_call_attempts ADD CONSTRAINT provider_attempts_one_cost_owner CHECK (
            (operation_quote_id IS NOT NULL AND scope_key IS NULL AND workspace_id IS NULL AND scope_cap_microusd IS NULL)
            OR (operation_quote_id IS NULL AND scope_key IS NOT NULL AND workspace_id IS NOT NULL AND scope_cap_microusd IS NOT NULL)
        )');
    }

    public function down(): void
    {
        if (DB::table('provider_call_attempts')->whereNotNull('scope_key')->exists()) {
            throw new RuntimeException('Cannot remove internal provider spend scopes while attempt records exist.');
        }
        DB::statement('ALTER TABLE provider_call_attempts DROP CONSTRAINT provider_attempts_one_cost_owner');
        DB::statement('ALTER TABLE provider_call_attempts ALTER COLUMN operation_quote_id SET NOT NULL');
        Schema::table('provider_call_attempts', function (Blueprint $table) {
            $table->dropIndex('provider_attempts_internal_scope_index');
            $table->dropColumn(['workspace_id', 'scope_key', 'scope_cap_microusd']);
        });
    }
};
