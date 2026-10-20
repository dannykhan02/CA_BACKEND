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
use App\Services\AiCredits\CreditAccountant;
use App\Services\AiCredits\QuoteService;
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
                'initial_credits' => QuoteService::enabled() ? (int) config('ai_credits.grants.free_trial_credits') : (int) config('billing.free_initial_credits'),
                'granted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($inserted && QuoteService::enabled()) {
                // Non-renewable saved AI credits through the same variable-price system.
                app(CreditAccountant::class)->grantSaved($workspace->id, (int) config('ai_credits.grants.free_trial_credits'), 'trial:'.$user->id,
                    'trial_grant', 'user', $user->id, $user->id);
            } elseif ($inserted) {
                $this->addCredits($workspace->id, (int) config('billing.free_initial_credits'));
                app(CreditLedger::class)->record($workspace->id, 'trial:'.$user->id, 'saved_document', 'credit',
                    (int) config('billing.free_initial_credits'), 'trial_grant', 'user', $user->id, $user->id,
                    WorkspaceCredit::where('workspace_id', $workspace->id)->value('documents_remaining'));
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

        if ($purchase->plan_key !== null) {
            app(SubscriptionService::class)->completePurchase($purchase);
        } else {
            $this->addCredits($purchase->workspace_id, $purchase->documents_purchased, true);
            $purchase->forceFill(['status' => 'completed', 'provider_transaction_id' => isset($purchase->paystack_response['data']['id']) ? (string) $purchase->paystack_response['data']['id'] : null])->save();
            app(CreditLedger::class)->record($purchase->workspace_id, 'legacy_purchase:'.$purchase->id, 'saved_document', 'credit',
                $purchase->documents_purchased, 'legacy_purchase', 'purchase', (string) $purchase->id, $purchase->user_id,
                WorkspaceCredit::where('workspace_id', $purchase->workspace_id)->value('documents_remaining'));
        }

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
        if (QuoteService::enabled()) {
            // Same value as the 10 legacy document units, expressed in AI credits (100 by default).
            $credits = max(0, (int) config('ai_credits.grants.referral_reward_credits'));
            app(CreditAccountant::class)->grantSaved($workspace->id, $credits, 'referral:'.$referral->id, 'referral_grant', 'referral', (string) $referral->id, $referrer->id);
            $documents = intdiv($credits, max(1, (int) config('ai_credits.legacy_saved_credits_per_document')));
        } else {
            $this->addCredits($workspace->id, $documents);
            app(CreditLedger::class)->record($workspace->id, 'referral:'.$referral->id, 'saved_document', 'credit',
                $documents, 'referral_grant', 'referral', (string) $referral->id, $referrer->id,
                WorkspaceCredit::where('workspace_id', $workspace->id)->value('documents_remaining'));
        }
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

    /**
     * The charge point. One credit per document, when its revision publishes — or, where revisions
     * are disabled and no publication exists, when it reaches Ready. Caller must hold the document
     * row lock inside that transaction, so the debit and the claim commit together.
     */
    public function accountForReadyDocument(Document $document, bool $ready = true): void
    {
        // Not gated on the flag: a reservation made while credits were on must settle the same way after the flag is turned off.
        if ($this->hasAiOperation($document->id)) {
            // AI credits are charged only for a delivered result: merged evidence alone ($ready = false) never debits.
            if ($ready) {
                app(CreditAccountant::class)->settleDocument($document->id, $document->uploaded_by);
                if ($document->credit_accounted_at === null && DB::table('billing_operations')->where('kind', 'document')
                    ->where('resource_id', $document->id)->where('status', 'completed')->exists()) {
                    $this->claimCharge($document);
                }
            }

            return;
        }
        // Merged evidence alone never debits in legacy accounting either: the $ready = false
        // caller (MergeDocumentEvidenceJob) runs long before anything is published.
        if (! $ready || $document->credit_accounted_at !== null) {
            return;
        }
        if (! $this->claimCharge($document)) {
            // A concurrent publication already claimed this document's single charge.
            return;
        }

        if (app(EntitlementService::class)->settleDocument($document)) {
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
        app(CreditLedger::class)->record($document->workspace_id, 'document_debit:'.$document->id, 'saved_document', 'debit',
            1, 'document_completed', 'document', $document->id, $document->uploaded_by, $credits->documents_remaining);
    }

    /**
     * Claim the one charge a document may ever incur, as a single conditional update. Two concurrent
     * or repeated publications race here and exactly one wins; the loser charges nothing. Persisted
     * immediately — including zero-balance completions — so retries and later reprocessing are free.
     */
    private function claimCharge(Document $document): bool
    {
        $at = now();
        $claimed = (bool) Document::whereKey($document->id)->whereNull('credit_accounted_at')
            ->update(['credit_accounted_at' => $at]);
        if ($claimed) {
            // Keep the caller's instance truthful without making the attribute dirty: callers
            // save the same model afterwards and must not write the column a second time.
            $document->setAttribute('credit_accounted_at', $at)->syncOriginalAttribute('credit_accounted_at');
        }

        return $claimed;
    }

    public function hasAiOperation(string $documentId): bool
    {
        return DB::table('billing_operations')->whereIn('kind', ['document', 'ocr', 'reanalysis'])->where('resource_id', $documentId)
            ->whereNotNull('amount_reserved')->exists();
    }
}
