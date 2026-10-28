<?php

namespace App\Services\AiCredits;

use App\Models\Document;
use App\Models\OperationQuote;

/**
 * The per-document credit payload (`credits` on the document resources), derived from the document's latest 'document' quote.
 * Scoped to the request: a list preloads every quote on the page with one query, so rendering it adds none per row.
 */
class DocumentCredits
{
    /** @var array<string, ?OperationQuote> document id => latest quote */
    private array $quotes = [];

    /** @param  iterable<string>  $documentIds */
    public function preload(iterable $documentIds): void
    {
        if (! QuoteService::enabled()) {
            return;
        }
        $ids = array_values(array_diff(array_unique([...$documentIds]), array_keys($this->quotes)));
        if ($ids === []) {
            return;
        }
        $this->quotes += array_fill_keys($ids, null);
        // The latest attempt is the highest number in operation_key ("document:<id>:<attempt>"), as QuoteService::issue counts it.
        $attempt = fn (OperationQuote $q) => (int) substr((string) strrchr($q->operation_key, ':'), 1);
        foreach (OperationQuote::where('kind', 'document')->whereIn('resource_id', $ids)->get() as $quote) {
            $current = $this->quotes[$quote->resource_id];
            if ($current === null || $attempt($quote) > $attempt($current)) {
                $this->quotes[$quote->resource_id] = $quote;
            }
        }
    }

    /**
     * @return ?array{quoted:?int,state:string,charged:?int,requiresConfirmation:bool,quotedCredits:int,chargedCredits:?int,releasedCredits:?int,settlementBasis:?string,heldCredits:?int,absorbedCredits:?int} null when credits are off or the document has no quote yet
     */
    public function for(Document $document): ?array
    {
        if (! QuoteService::enabled()) {
            return null;
        }
        $this->preload([$document->id]);
        $quote = $this->quotes[$document->id] ?? null;
        if (! $quote) {
            return null;
        }
        $confirmed = (bool) ($document->ai_pipeline['credit_quote_confirmed'] ?? false);
        $needsClick = $quote->status === 'quoted' && ! $confirmed
            && app(QuoteService::class)->requiresConfirmation((int) $quote->credits, (int) ($quote->preflight['pages'] ?? 0));
        $ended = in_array($document->status, ['Failed', 'Needs Review'], true);

        $state = match (true) {
            $quote->status === 'settled' => 'charged',
            $quote->status === 'released', $ended => 'not_charged',
            $quote->status === 'reserved' => 'reserved',
            $needsClick => 'awaiting_confirmation',
            default => 'quoted',
        };

        // A quote settled before settlement columns existed has null charged_credits: it was charged its quote.
        $charged = $state === 'charged' ? (int) ($quote->charged_credits ?? $quote->credits) : null;

        return ['quoted' => (int) $quote->credits, 'state' => $state, 'charged' => $charged,
            'requiresConfirmation' => $state === 'awaiting_confirmation',
            'quotedCredits' => (int) $quote->credits, 'chargedCredits' => $charged,
            'releasedCredits' => $state === 'charged' ? (int) ($quote->released_credits ?? 0) : null,
            'settlementBasis' => $state === 'charged' ? ($quote->settlement_basis ?? 'quoted') : null,
            // What was held (null before a hold) and the credits above the hold that were not charged to the customer.
            'heldCredits' => in_array($quote->status, ['reserved', 'settled'], true) ? (int) ($quote->held_credits ?? $quote->credits) : null,
            'absorbedCredits' => $state === 'charged' ? (int) ($quote->absorbed_credits ?? 0) : null];
    }
}
