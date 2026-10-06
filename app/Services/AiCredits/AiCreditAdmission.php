<?php

namespace App\Services\AiCredits;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Admission gate: price the work from local signals, reserve the credits, and only then let paid AI start.
 * Workspaces still on a pre-rollout subscription period keep the legacy 1-unit rules (mode 'legacy').
 */
class AiCreditAdmission
{
    public function __construct(private QuoteService $quotes, private CreditAccountant $accountant) {}

    /** True when paid AI may proceed for this document now. Never calls a provider. */
    public function admitDocument(Document $document): bool
    {
        if (! QuoteService::enabled() || $this->accountant->mode($document->workspace_id) === 'legacy' || $document->credit_accounted_at) {
            return true;
        }
        $priced = $this->quotes->quoteDocument($document);
        if ($priced['declined']) {
            return $this->refuse($document, $priced['reason'] === 'document_too_large'
                ? 'This document is too large to analyse with AI credits.' : 'This document cannot be analysed within its credit allowance.');
        }
        $quote = $priced['quote'];
        if ($quote->status === 'quoted' && $this->quotes->requiresConfirmation($quote->band) && ! ($document->ai_pipeline['credit_quote_confirmed'] ?? false)) {
            // Explicit consent before spending a large charge: nothing is reserved and nothing paid runs.
            $document->forceFill(['ai_pipeline' => [...($document->ai_pipeline ?? []), 'awaiting_credit_confirmation' => true,
                'credit_quote' => ['quote_id' => $quote->id, 'credits' => $quote->credits, 'band' => $quote->band]]])->save();

            return false;
        }
        try {
            $this->accountant->reserve($quote, $document->uploaded_by);
        } catch (HttpException $e) {
            if ($e->getStatusCode() !== 402) {
                throw $e;
            }

            return $this->refuse($document, $e->getMessage());
        }
        if ($document->ai_pipeline['awaiting_credit_confirmation'] ?? false) {
            $document->forceFill(['ai_pipeline' => [...$document->ai_pipeline, 'awaiting_credit_confirmation' => false]])->save();
        }

        return true;
    }

    /** The customer accepts the quoted credits for a large analysis; the caller then resumes the pipeline. */
    public function confirmDocument(Document $document): void
    {
        $document->forceFill(['ai_pipeline' => [...($document->ai_pipeline ?? []), 'credit_quote_confirmed' => true]])->save();
    }

    /** OCR is priced from the page count and refused above the page cap, before any vision call. */
    public function admitOcr(Document $document, int $pages): bool
    {
        if (! QuoteService::enabled() || $this->accountant->mode($document->workspace_id) === 'legacy') {
            return true;
        }
        $priced = $this->quotes->quoteOcr($document, $pages);
        if ($priced['declined']) {
            return $this->refuse($document, $priced['reason'] === 'ocr_page_limit'
                ? sprintf('This scanned document has more than %d pages, which is above the OCR limit.', config('ai_credits.ocr.max_pages'))
                : 'No pages could be read from this scan.');
        }
        try {
            // Leave room for the cheapest analysis band so a scan cannot be OCR'd and then left unpaid.
            $need = $priced['quote']->credits + $this->quotes->minimumDocumentCredits();
            if ($this->accountant->available($document->workspace_id) < $need) {
                abort(402, sprintf('Insufficient credits: %d available, %d required.', $this->accountant->available($document->workspace_id), $need));
            }
            $this->accountant->reserve($priced['quote'], $document->uploaded_by);
        } catch (HttpException $e) {
            if ($e->getStatusCode() !== 402) {
                throw $e;
            }

            return $this->refuse($document, $e->getMessage());
        }

        return true;
    }

    private function refuse(Document $document, string $message): bool
    {
        $this->accountant->releaseDocument($document->id, 'admission_refused');
        $document->forceFill(['status' => 'Failed', 'error_message' => $message])->save();

        return false;
    }

    /**
     * An explicit user re-run. Internal recovery never comes here and is free. A document whose original
     * contract was never delivered (credits released) is re-admitted as a normal analysis; a delivered one is
     * charged once as a re-analysis, with its own provider ceiling. Aborts 402/422 before any state changes.
     */
    public function admitReanalysis(Document $document, bool $summaryOnly, ?string $userId = null): void
    {
        if (! QuoteService::enabled() || $this->accountant->mode($document->workspace_id) === 'legacy' || $summaryOnly) {
            return; // a summary refresh costs no credits; the settled quote's ceiling still bounds its spend
        }
        $delivered = $document->credit_accounted_at !== null || DB::table('billing_operations')
            ->where('kind', 'document')->where('resource_id', $document->id)->where('status', 'completed')->whereNotNull('amount_reserved')->exists();
        $priced = $this->quotes->quoteDocument($document, $delivered ? 'reanalysis' : 'document', $delivered);
        abort_if($priced['declined'], 422, 'This document cannot be re-analysed within its credit allowance.');
        $quote = $priced['quote'];
        $this->accountant->reserve($quote, $userId);
        // The re-run gets its own ceiling on top of what the pipeline already committed (incremental route only).
        if (($document->ai_pipeline['route'] ?? null) !== 'incremental') {
            return;
        }
        $committed = (float) DocumentChunk::where('document_id', $document->id)
            ->where('pipeline_key', $document->ai_pipeline['key'] ?? '')->sum('reserved_cost');
        DB::transaction(function () use ($document, $committed, $quote) {
            $locked = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['ai_pipeline' => [...($locked->ai_pipeline ?? []), 'budget_usd' => round($committed + $quote->provider_cost_cap_usd, 6)]])->save();
        });
    }
}
