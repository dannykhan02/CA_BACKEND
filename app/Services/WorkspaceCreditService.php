<?php

namespace App\Services;

use App\Enums\WorkspaceType;
use App\Models\CreditPurchase;
use App\Models\Document;
use App\Models\Referral;
use App\Models\TrialGrant;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCredit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WorkspaceCreditService
{
    public function grantTrial(Workspace $workspace, User $user, string $ip, ?string $fingerprint): bool
    {
        return DB::transaction(function () use ($workspace, $user, $ip, $fingerprint) {
            $email = mb_strtolower(trim($user->email));
            $fingerprint = $fingerprint ?: null;
            $exists = TrialGrant::where('email', $email)->orWhere('ip_address', $ip)
                ->when($fingerprint !== null, fn ($query) => $query->orWhere('fingerprint', $fingerprint))
                ->exists();
            if ($exists) {
                return false;
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
                $this->addCredits($workspace->id, (int) config('credits.trial_documents'));
            }

            return (bool) $inserted;
        });
    }

    /** Caller holds the purchase row lock and has verified the Paystack charge. */
    public function completePurchase(CreditPurchase $purchase): void
    {
        if ($purchase->status !== 'pending') {
            return;
        }

        // Serialize completions across ALL workspaces for this purchaser.
        // A purchase-row lock alone cannot arbitrate two different purchases.
        if ($purchase->user_id !== null) {
            User::whereKey($purchase->user_id)->lockForUpdate()->firstOrFail();
        }

        $this->addCredits($purchase->workspace_id, $purchase->documents_purchased, true);
        $purchase->forceFill(['status' => 'completed'])->save();

        if ($purchase->user_id !== null && ! CreditPurchase::where('user_id', $purchase->user_id)
            ->where('status', 'completed')->whereKeyNot($purchase->id)->exists()) {
            $this->grantReferralReward($purchase);
        }
    }

    private function grantReferralReward(CreditPurchase $purchase): void
    {
        $referral = Referral::where('referred_user_id', $purchase->user_id)
            ->where('status', 'pending')->where('reward_eligible', true)->lockForUpdate()->first();
        if (! $referral) {
            return;
        }

        $referrer = User::whereKey($referral->referralCode->user_id)->lockForUpdate()->firstOrFail();
        $buyer = User::findOrFail($purchase->user_id);
        // Recheck identity at payout too, including email changes since signup.
        if ($referrer->id === $buyer->id
            || mb_strtolower(trim($referrer->email)) === mb_strtolower(trim($buyer->email))) {
            $referral->update(['reward_eligible' => false, 'ineligible_reason' => 'self_referral']);

            return;
        }

        $workspace = $referrer->workspaces()->where('type', WorkspaceType::Personal)->first();
        if (! $workspace) {
            // Older organization-only accounts still earn personal rewards.
            // No IP means no trial, and their active workspace stays selected.
            $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($referrer, makeCurrent: false);
        }
        $documents = max(0, (int) config('credits.referral_reward_documents'));
        $this->addCredits($workspace->id, $documents);
        $referral->update([
            'status' => 'rewarded',
            'reward_documents' => $documents,
            'rewarded_workspace_id' => $workspace->id,
            'rewarded_at' => now(),
        ]);
    }

    /** Shared balance mutation for trials, purchases, and referral rewards. */
    private function addCredits(string $workspaceId, int $documents, bool $purchased = false): void
    {
        $credits = WorkspaceCredit::where('workspace_id', $workspaceId)->lockForUpdate()->firstOrFail();
        $credits->documents_remaining += $documents;
        if ($purchased) {
            $credits->documents_purchased_total += $documents;
        }
        $credits->save();
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
