<?php

namespace App\Services\AiCredits;

use App\Models\Document;
use App\Models\OcrResult;
use App\Models\OperationQuote;
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

    /** The least a document can cost, which is all the start-of-processing balance check needs: the smallest band. */
    public function minimumDocumentCredits(): int
    {
        return (int) min(array_column(config('ai_credits.bands'), 'credits'));
    }

    public function providerCap(int $credits): float
    {
        return round($credits * (float) config('ai_credits.provider_cost_usd_per_credit'), 6);
    }

    /**
     * @param  float  $extraCostUsd  Provider cost the band's cap must also cover (OCR of a scan).
     * @param  ?string  $minBand  The cheapest band this document may be quoted in (a scan never prices below it).
     * @return array{band:string,credits:int,cap:float,shifted:bool,declined:bool,reason:?string}
     */
    public function classify(int $tokens, bool $dense, float $synthesisFloorUsd = 0.0, float $extraCostUsd = 0.0, ?string $minBand = null): array
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
        $floorIndex = $minBand === null ? false : array_search($minBand, $names, true);
        if (! $declined && $floorIndex !== false) {
            $index = max($index, $floorIndex);
        }
        // A band that cannot fund what the pipeline must reserve (its synthesis reservation on the incremental route, a
        // scan's OCR bound) moves up, or the document is declined. There is no size-based "minimum completion" gate: the
        // band edges are the price, and the caps were checked against measured cost (config/ai_credits.php).
        $needed = $synthesisFloorUsd + $extraCostUsd;
        while (! $declined && $needed > 0 && $this->providerCap($bands[$names[$index]]['credits']) < $needed) {
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

    /** A quote of at least `confirm_from_credits`, or for a document of more than `confirm_over_pages`, needs consent first. */
    public function requiresConfirmation(int $credits, int $pages = 0): bool
    {
        return $credits >= (int) config('ai_credits.confirm_from_credits')
            || $pages > (int) config('ai_credits.confirm_over_pages');
    }

    /** Characters per token for a document type; the map's `default` for any type it does not list. */
    public function charsPerToken(?string $type): float
    {
        $map = config('ai_credits.chars_per_token');
        $cpt = (float) ($map[strtoupper((string) $type)] ?? $map['default'] ?? 3.0);

        return $cpt > 0 ? $cpt : 3.0;
    }

    /** Tokens the quote is priced on (see tokenBasis). */
    public function estimateTokens(Document $document, int $ocrPages = 0): int
    {
        return $this->tokenBasis($document->type, strlen((string) $document->extracted_text), $this->providerTokens($document), $ocrPages)['tokens'];
    }

    /** The provider's own token count for this document, when the pipeline has already asked for it. */
    public function providerTokens(Document $document): ?int
    {
        return ($document->ai_pipeline['count_method'] ?? null) === 'anthropic' && isset($document->ai_pipeline['tokens'])
            ? (int) $document->ai_pipeline['tokens'] : null;
    }

    /** The same figure from its parts, for reports that must not load document text. */
    public function estimateTokensFor(?string $type, int $textBytes, ?int $providerTokens, int $ocrPages = 0): int
    {
        return $this->tokenBasis($type, $textBytes, $providerTokens, $ocrPages)['tokens'];
    }

    /**
     * Where a quote's token figure comes from, and what was done to it.
     *
     * The provider's count is used as is whenever it exists. Otherwise the local estimate (text bytes over a per-type
     * ratio) is multiplied by `local_estimate_multiplier`, because the estimate runs about +/-30% off the provider's count.
     * A spreadsheet is then multiplied by `spreadsheet_token_multiplier`. A scan is at least its page count times
     * `ocr.tokens_per_page`. `route_tokens` is the unscaled figure the real route decision is closest to.
     *
     * @return array{tokens:int,raw_tokens:int,route_tokens:int,token_source:string,estimate_multiplier:float,spreadsheet_multiplier:float}
     */
    public function tokenBasis(?string $type, int $textBytes, ?int $providerTokens, int $ocrPages = 0): array
    {
        $sheet = strtoupper((string) $type) === 'XLSX' ? (float) config('ai_credits.spreadsheet_token_multiplier') : 1.0;
        if ($providerTokens !== null) {
            $source = 'provider_count';
            $raw = $providerTokens;
            $estimateMultiplier = 1.0;
        } else {
            $source = 'local_estimate';
            $raw = max(1, (int) ceil($textBytes / $this->charsPerToken($type)));
            $estimateMultiplier = (float) config('ai_credits.local_estimate_multiplier');
        }
        $tokens = (int) ceil(round($raw * $estimateMultiplier * $sheet, 6));
        $pageTokens = $ocrPages * (int) config('ai_credits.ocr.tokens_per_page');
        if ($ocrPages > 0 && $pageTokens > $tokens) {
            $tokens = $pageTokens;
            $source = 'ocr_pages';
        }

        return ['tokens' => $tokens, 'raw_tokens' => $raw, 'route_tokens' => max($raw, $pageTokens),
            'token_source' => $source, 'estimate_multiplier' => $estimateMultiplier, 'spreadsheet_multiplier' => $sheet];
    }

    /** Pages of this document that were read by OCR (an image is one page), or 0 when none were. */
    public function ocrPages(Document $document): int
    {
        $read = OcrResult::where('document_id', $document->id)->distinct()->count('page_number');
        if ($read > 0) {
            return $read;
        }

        return in_array($document->type, ['JPG', 'PNG'], true) ? 1 : 0;
    }

    /** What a scan of this many pages will cost, estimated before any page is read. */
    public function classifyScan(int $pages): array
    {
        return $this->classify($pages * (int) config('ai_credits.ocr.tokens_per_page'), false, 0.0,
            $pages * (float) config('ai_credits.ocr.provider_cost_usd_per_page'), config('ai_credits.ocr.min_band'));
    }

    /**
     * What the incremental pipeline reserves for synthesis before it starts: the primary request, one degraded fallback and
     * one repair, each at its worst-case cost (AnthropicClient::synthesisReservation). A reservation, not a cost.
     */
    public function synthesisFloorUsd(Document $document): float
    {
        try {
            $r = app(AnthropicClient::class)->synthesisReservation($document, null, 0);

            return (float) ($r['synthesis_reserved_usd'] ?? 0) + (float) ($r['synthesis_degraded_reserved_usd'] ?? 0) + (float) ($r['repair_reserved_usd'] ?? 0);
        } catch (\Throwable) {
            return 0.0; // local estimate unavailable
        }
    }

    /** Local signals only: token count (no paid count call), density and file type. */
    public function documentSignals(Document $document): array
    {
        $text = (string) $document->extracted_text;
        $ocrPages = $this->ocrPages($document);
        $basis = $this->tokenBasis($document->type, strlen($text), $this->providerTokens($document), $ocrPages);
        $tokens = $basis['tokens'];
        $density = app(ExtractionCapacity::class)->density($text, $document->type);

        $floor = 0.0;
        // Only the incremental pipeline admits spend against reservations; small documents take the legacy path.
        $incremental = config('document_intelligence.incremental')
            && ! ($basis['route_tokens'] <= config('document_intelligence.large_tokens') && mb_strlen($text) <= config('document_processing.max_extraction_chars'));
        if ($incremental) {
            $floor = $this->synthesisFloorUsd($document);
        }

        return ['incremental_route' => (bool) $incremental, 'synthesis_floor_usd' => round($floor, 6), 'tokens' => $tokens, 'raw_tokens' => $basis['raw_tokens'],
            'token_source' => $basis['token_source'], 'estimate_multiplier' => $basis['estimate_multiplier'],
            'spreadsheet_multiplier' => $basis['spreadsheet_multiplier'], 'dense' => $density['dense'], 'spreadsheet' => $density['spreadsheet'],
            'numeric_ratio' => $density['numeric_ratio'], 'tabular_line_ratio' => $density['tabular_line_ratio'],
            'type' => $document->type, 'pages' => max((int) $document->pages, $ocrPages), 'ocr_pages' => $ocrPages];
    }

    /**
     * OCR's provider spend bound, from the page count BEFORE any vision call. It costs the customer nothing by itself:
     * the scan is priced as a document by its band, and that band's cap is raised to cover this (see classify()).
     */
    public function classifyOcr(int $pages): array
    {
        $o = config('ai_credits.ocr');
        if ($pages < 1 || $pages > $o['max_pages']) {
            return ['declined' => true, 'reason' => $pages < 1 ? 'no_pages' : 'ocr_page_limit', 'credits' => 0, 'cap' => 0.0, 'pages' => $pages];
        }

        return ['declined' => false, 'reason' => null, 'credits' => 0, 'pages' => $pages,
            'cap' => round(max($pages * $o['provider_cost_usd_per_page'], (float) $o['min_cap_usd']), 6)];
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

    /**
     * What a document would be quoted right now, without saving, reserving or changing anything: the same signals and
     * classification quoteDocument() uses. For reports.
     *
     * @return array{signals:array,classification:array}
     */
    public function previewDocument(Document $document): array
    {
        $signals = $this->documentSignals($document);
        $scan = $signals['ocr_pages'] > 0;

        return ['signals' => $signals, 'classification' => $this->classify($signals['tokens'], $signals['dense'], $signals['synthesis_floor_usd'],
            $scan ? $signals['ocr_pages'] * (float) config('ai_credits.ocr.provider_cost_usd_per_page') : 0.0,
            $scan ? config('ai_credits.ocr.min_band') : null)];
    }

    public function quoteDocument(Document $document, string $kind = 'document', bool $newRequest = false): array
    {
        ['signals' => $signals, 'classification' => $c] = $this->previewDocument($document);
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
