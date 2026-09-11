<?php

namespace App\Services;

use App\Models\Document;
use App\Models\TrialGrant;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCredit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WorkspaceCreditService
{
    public function grantTrial(Workspace $workspace, User $user, string $ip, ?string $fingerprint): void
    {
        DB::transaction(function () use ($workspace, $user, $ip, $fingerprint) {
            $email = mb_strtolower(trim($user->email));
            $fingerprint = $fingerprint ?: null;
            $exists = TrialGrant::where('email', $email)->orWhere('ip_address', $ip)
                ->when($fingerprint !== null, fn ($query) => $query->orWhere('fingerprint', $fingerprint))
                ->exists();
            if ($exists) {
                return;
            }

            // PostgreSQL ON CONFLICT DO NOTHING waits for a competing insert:
            // only its winner grants credits, without aborting signup's transaction.
            $inserted = DB::table('trial_grants')->insertOrIgnore([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'email' => $email,
                'ip_address' => $ip,
                'fingerprint' => $fingerprint,
                'granted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($inserted) {
                $workspace->credits()->increment('documents_remaining', config('credits.trial_documents'));
            }
        });
    }

    /** Caller must hold the document row lock inside its Ready transaction. */
    public function accountForReadyDocument(Document $document): void
    {
        if ($document->credit_accounted_at !== null) {
            return;
        }

        $credits = WorkspaceCredit::where('workspace_id', $document->workspace_id)->lockForUpdate()->firstOrFail();
        if ($credits->documents_remaining <= 0) {
            Log::warning('Document completed without remaining workspace credits.', [
                'workspace_id' => $document->workspace_id,
                'document_id' => $document->id,
            ]);
        }
        $credits->documents_remaining = max(0, $credits->documents_remaining - 1);
        $credits->save();
        // Persisted with Ready, including zero-balance completions, so retries
        // or later reprocessing cannot charge this document a second time.
        $document->forceFill(['credit_accounted_at' => now()]);
    }
}
