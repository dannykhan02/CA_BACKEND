<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * documents.power_bi_status was originally enum('synced','not-synced',
 * 'failed') — 'excluded' was never a valid value, discovered via a real
 * check-constraint violation when DocumentObserver correctly tried to
 * write it for a Restricted document. 'excluded' is semantically distinct
 * from 'not-synced': the latter means "hasn't synced yet, might later,"
 * the former means "will never sync while classification stays Restricted
 * — deliberate, not pending." Widening the constraint to make the
 * observer's already-correct logic actually persistable.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_power_bi_status_check');
        DB::statement(
            "ALTER TABLE documents ADD CONSTRAINT documents_power_bi_status_check
             CHECK (power_bi_status IN ('synced','not-synced','failed','excluded'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_power_bi_status_check');
        DB::statement(
            "ALTER TABLE documents ADD CONSTRAINT documents_power_bi_status_check
             CHECK (power_bi_status IN ('synced','not-synced','failed'))"
        );
    }
};