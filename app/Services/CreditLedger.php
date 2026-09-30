<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Append-only evidence. Counters remain authoritative for pre-ledger history. */
class CreditLedger
{
    public function record(string $workspaceId, string $reference, string $unit, string $direction, int $amount,
        string $reason, ?string $relatedType = null, ?string $relatedId = null, ?string $userId = null,
        ?int $resultingBalance = null): void
    {
        DB::table('credit_ledger')->insertOrIgnore([
            'workspace_id' => $workspaceId, 'user_id' => $userId,
            'unit' => $unit, 'direction' => $direction, 'amount' => $amount,
            'reason' => $reason, 'related_type' => $relatedType, 'related_id' => $relatedId,
            'reference' => $reference, 'resulting_balance' => $resultingBalance,
            'created_at' => now(),
        ]);
    }
}
