<?php

namespace App\Services\AiCredits;

use App\Models\Document;
use App\Models\OperationQuote;
use App\Services\AI\Incremental\ChunkPlanner;
use App\Services\AI\Incremental\ExtractionCapacity;
use App\Services\AnthropicClient;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Local, pre-provider pricing. Nothing here calls a paid API. */
class QuoteService
{
    public static function enabled(): bool
    {
        return (bool) config('ai_credits.enabled');
    }

    /** Band names in ascending price order. */
    public function bandNames(): array
    {
        return array_keys(config('ai_credits.bands'));
    }

    public function minimumDocumentCredits(): int
    {
        return (int) min(array_column(config('ai_credits.bands'), 'credits'));
    }

    public function providerCap(int $credits): float
    {
        return round($credits * (float) config('ai_credits.provider_cost_usd_per_credit'), 6);
    }

    /** Conservative cost a document of this size needs to finish, used to refuse caps that cannot work. */
    public function minCompletionCostUsd(int $tokens, float $synthesisFloorUsd = 0.0): float
    {
        $c = config('ai_credits.min_completion_cost_usd');

        // The pipeline must be able to reserve its synthesis (primary, one fallback, one repair) inside the cap.
        return round(max($c['base'], $synthesisFloorUsd) + $tokens / 1000 * $c['per_1000_tokens'], 6);
    }

    /**
     * @return array{band:string,credits:int,cap:float,shifted:bool,declined:bool,reason:?string}
     */
    public function classify(int $tokens, bool $dense, float $synthesisFloorUsd = 0.0): array
    {
        $bands = config('ai_credits.bands');
        $names = array_keys($bands);
        $index = count($names) - 1;
        $declined = true;
        foreach ($names as $i => $name) {
            if ($tokens <= $bands[$name]['max_tokens']) {
                $index = $i;
                $declined = false;
                break;
            }
        }
        $reason = $declined ? 'document_too_large' : null;
        $start = $index;
        if ($dense && ! $declined) {
            $index = min(count($names) - 1, $index + (int) config('ai_credits.dense_band_shift'));
        }
        // A band whose provider ceiling cannot pay for the minimum useful completion moves up, or is declined.
        $needed = $this->minCompletionCostUsd($tokens, $synthesisFloorUsd);
        while (! $declined && $this->providerCap($bands[$names[$index]]['credits']) < $needed) {
            if ($index === count($names) - 1) {
                $declined = true;
                $reason = 'provider_cost_exceeds_top_band';
                break;
            }
            $index++;
        }
        $band = $names[$index];

        return ['band' => $band, 'credits' => (int) $bands[$band]['credits'], 'cap' => $this->providerCap((int) $bands[$band]['credits']),
            'shifted' => $index !== $start, 'declined' => $declined, 'reason' => $reason];
    }

    public function requiresConfirmation(string $band): bool
    {
        $names = $this->bandNames();
        $from = array_search(config('ai_credits.confirm_from_band'), $names, true);

        return $from !== false && array_search($band, $names, true) >= $from;
    }

    /** Local signals only: token count (no paid count call), density and file type. */
    public function documentSignals(Document $document): array
    {
        $text = (string) $document->extracted_text;
        $tokens = ($document->ai_pipeline['count_method'] ?? null) === 'anthropic' && isset($document->ai_pipeline['tokens'])
            ? (int) $document->ai_pipeline['tokens']
            : app(ChunkPlanner::class)->estimate($text);
        $density = app(ExtractionCapacity::class)->density($text, $document->type);

        $floor = 0.0;
        // Only the incremental pipeline admits spend against reservations; small documents take the legacy path.
        $incremental = config('document_intelligence.incremental')
            && ! ($tokens <= config('document_intelligence.large_tokens') && mb_strlen($text) <= config('document_processing.max_extraction_chars'));
        if ($incremental) {
            try {
                $r = app(AnthropicClient::class)->synthesisReservation($document, null, 0);
                $floor = (float) ($r['synthesis_reserved_usd'] ?? 0) + (float) ($r['synthesis_degraded_reserved_usd'] ?? 0) + (float) ($r['repair_reserved_usd'] ?? 0);
            } catch (\Throwable) {
                $floor = 0.0; // local estimate unavailable: the configured minimum still applies
            }
        }

        return ['incremental_route' => (bool) $incremental, 'synthesis_floor_usd' => round($floor, 6), 'tokens' => $tokens, 'dense' => $density['dense'], 'spreadsheet' => $density['spreadsheet'],
            'numeric_ratio' => $density['numeric_ratio'], 'tabular_line_ratio' => $density['tabular_line_ratio'],
            'type' => $document->type, 'pages' => (int) $document->pages];
    }

    /** OCR is quoted from the page count BEFORE any vision call. */
    public function classifyOcr(int $pages): array
    {
        $o = config('ai_credits.ocr');
        if ($pages < 1 || $pages > $o['max_pages']) {
            return ['declined' => true, 'reason' => $pages < 1 ? 'no_pages' : 'ocr_page_limit', 'credits' => 0, 'cap' => 0.0, 'pages' => $pages];
        }
        $credits = $o['surcharge_credits'] + max(0, $pages - $o['included_pages']) * $o['extra_credits_per_page'];

        return ['declined' => false, 'reason' => null, 'credits' => $credits, 'pages' => $pages,
            'cap' => round($pages * $o['provider_cost_usd_per_page'], 6)];
    }

    public function reanalysisCredits(array $classification): int
    {
        return config('ai_credits.reanalysis.pricing') === 'fixed'
            ? (int) config('ai_credits.reanalysis.fixed_credits') : $classification['credits'];
    }

    /**
     * One quote per (kind, resource, attempt). A quote still quoted/reserved is returned unchanged, so a
     * queue retry or duplicate delivery can never re-price; a settled/released one yields a new attempt only
     * for a new request. $priced = ['band','credits','cap','preflight'].
     */
    public function issue(string $workspaceId, string $kind, string $resourceId, array $priced, bool $newRequest = false): OperationQuote
    {
        $latestOf = fn () => OperationQuote::where('kind', $kind)->where('resource_id', $resourceId)->lockForUpdate()->get()
            ->sortByDesc(fn ($q) => (int) substr(strrchr($q->operation_key, ':'), 1))->first();
        try {
            return DB::transaction(function () use ($workspaceId, $kind, $resourceId, $priced, $newRequest, $latestOf) {
                $latest = $latestOf();
                if ($latest && in_array($latest->status, ['quoted', 'reserved'], true)) {
                    return $latest;
                }
                if ($latest && $latest->status === 'settled' && ! $newRequest) {
                    return $latest;
                }
                $attempt = $latest ? ((int) substr(strrchr($latest->operation_key, ':'), 1)) + 1 : 1;

                return OperationQuote::create([
                    'workspace_id' => $workspaceId, 'kind' => $kind, 'resource_id' => $resourceId,
                    'operation_key' => "{$kind}:{$resourceId}:{$attempt}", 'quote_version' => config('ai_credits.quote_version'),
                    'band' => $priced['band'], 'credits' => $priced['credits'], 'provider_cost_cap_usd' => $priced['cap'],
                    'preflight' => $priced['preflight'], 'status' => 'quoted',
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request issued the same attempt first: that quote is the price.
            return DB::transaction($latestOf);
        }
    }

    public function quoteDocument(Document $document, string $kind = 'document', bool $newRequest = false): array
    {
        $signals = $this->documentSignals($document);
        $c = $this->classify($signals['tokens'], $signals['dense'], $signals['synthesis_floor_usd']);
        if ($c['declined']) {
            return ['declined' => true, 'reason' => $c['reason'], 'signals' => $signals];
        }
        $credits = $kind === 'reanalysis' ? $this->reanalysisCredits($c) : $c['credits'];
        $quote = $this->issue($document->workspace_id, $kind, $document->id, ['band' => $c['band'], 'credits' => $credits,
            'cap' => $this->providerCap($credits),
            'preflight' => $signals + ['band_shifted_for_cost' => $c['shifted']]], $newRequest);

        return ['declined' => false, 'quote' => $quote];
    }

    public function quoteOcr(Document $document, int $pages): array
    {
        $c = $this->classifyOcr($pages);
        if ($c['declined']) {
            return ['declined' => true, 'reason' => $c['reason']];
        }
        $quote = $this->issue($document->workspace_id, 'ocr', $document->id, ['band' => 'ocr', 'credits' => $c['credits'],
            'cap' => $c['cap'], 'preflight' => ['pages' => $pages, 'type' => $document->type]]);

        return ['declined' => false, 'quote' => $quote];
    }

    public function quoteComparison(string $workspaceId, string $comparisonId, bool $newRequest = false): OperationQuote
    {
        $credits = (int) config('ai_credits.comparison.credits');

        return $this->issue($workspaceId, 'comparison', $comparisonId, ['band' => 'comparison', 'credits' => $credits,
            'cap' => $this->providerCap($credits), 'preflight' => ['kind' => 'ai_terms']], $newRequest);
    }
}
