<?php

namespace App\Services\Intelligence;

use App\Models\DocumentSourceSpan;
use App\Services\AI\Incremental\EvidenceMerger;
use App\Services\AI\Incremental\EvidenceSchema;
use App\Exceptions\AiProcessingException;

/** Read-only explanation of unknown metric provenance; never changes the projected record. */
class ProvenanceDiagnostic
{
    public function __construct(private EvidenceMerger $normalizer, private MeasurementParser $measurements) {}

    /** @param array<string,mixed> $record @param array<string,DocumentSourceSpan> $spans */
    public function classify(array $record, array $spans, string $extractedText): array
    {
        $data = $record['data'] ?? [];
        $source = $record['sources'][0] ?? [];
        $quote = (string) ($data['quote'] ?? $source['quote'] ?? '');
        $value = trim((string) ($data['value'] ?? ''));
        $period = trim((string) ($data['period'] ?? ''));
        $reason = $this->failureReason($data, $quote);
        $cited = $spans[$source['span_id'] ?? ''] ?? null;
        if ($cited && isset($source['extraction_version'])
            && $source['extraction_version'] !== $cited->extraction_version) {
            $cited = null;
        }
        $nearby = $this->context($record, $cited, $spans, $extractedText);
        $base = [
            'source_id' => $record['source_id'] ?? null,
            'label' => $data['label'] ?? null,
            'stored_value' => $data['value'] ?? null,
            'period' => $data['period'] ?? null,
            'page' => $source['page'] ?? $record['page'] ?? null,
            'span' => $cited ? $this->spanMetadata($cited) : null,
            'cited_quote' => $this->excerpt($quote),
            'original_reason' => $reason,
            'diagnostic_bucket' => 'ambiguous',
            'diagnostic_reason' => 'Stored evidence does not resolve the provenance failure deterministically.',
            'missing_component' => null,
            'supporting_spans' => [],
            'disagreement' => null,
        ];

        if ($quote === '' || $value === '' || $reason === 'other') {
            return $base;
        }

        $measurement = $this->measurements->parse($value, $data['unit'] ?? null, $data['label'] ?? null);
        $quoteMatches = $measurement ? $this->matchingNumbers($measurement, $quote, $data) : [];
        $valueInQuote = $this->contains($quote, $value);
        $periodInQuote = $period === '' || $this->contains($quote, $period);

        // A matching number alone cannot validate a different metric's denominator or subject.
        if ($this->semanticConflict((string) ($data['label'] ?? ''), $quote)) {
            return $this->unsupported($base, 'label/evidence semantic mismatch',
                'The cited percentage describes humanitarian action, not the label’s total-spending denominator.');
        }

        if ($reason === 'value_not_in_quote' && ! $periodInQuote) {
            // CR-001 reports the first failed dimension. A second unsupported period must not
            // be hidden by a numeric-format match on the value.
            foreach ($nearby as $context) {
                if ($context['relationship'] === 'same_table_context'
                    && ! preg_match('/\b(?:year|period|fy\s*\d{4}|as\s+at|as\s+of)\b/iu', $context['text'])) {
                    continue;
                }
                if ($this->contains($context['text'], $period) && ($valueInQuote || $quoteMatches !== [])) {
                    $base['missing_component'] = 'period';
                    $base['supporting_spans'] = [[...$context['span'],
                        'relationship' => $context['relationship'], 'text' => $this->excerpt($context['text'])]];

                    return $this->bucket($base, 'surrounding_context_supported',
                        'The quote grounds the quantity and bounded context supplies the otherwise missing period.');
                }
            }

            return $this->bucket($base, 'ambiguous',
                'Both value wording and period grounding need review; the period is not in bounded context.');
        }

        if ($reason === 'value_not_in_quote' && $quoteMatches !== []) {
            if ($this->generatedWording($value)) {
                return $this->bucket($base, 'extraction_wording_mismatch',
                    'The quote states the same quantity, but the stored value adds descriptive wording.');
            }
            if ($this->safeEquivalent($measurement, $quoteMatches)) {
                return $this->bucket($base, 'numeric_equivalent',
                    'MeasurementParser gives the stored value and cited notation the same magnitude, scale, and currency.');
            }
        }

        if ($reason === 'value_not_in_quote' && $measurement !== null
            && $this->unscaledNumberInQuote($measurement, $quote, $data)) {
            foreach ($nearby as $context) {
                if ($this->unitContextSupports($measurement, $context['text'])) {
                    $base['missing_component'] = 'unit';
                    $base['supporting_spans'] = [[...$context['span'],
                        'relationship' => $context['relationship'], 'text' => $this->excerpt($context['text'])]];

                    return $this->bucket($base, 'surrounding_context_supported',
                        'The cited row supplies the number and the bounded header supplies its currency and scale.');
                }
            }
        }

        $missing = $reason === 'period_not_in_quote' ? 'period' : 'value';
        if ($reason === 'due_date_not_grounded') {
            $missing = 'due_date';
        }
        if ($reason === 'value_not_in_quote' && $quoteMatches !== []) {
            $missing = $this->missingUnitComponent($measurement, $quoteMatches);
        }
        $needle = match ($missing) {
            'period' => $period,
            'due_date' => (string) ($data['due_date'] ?? ''),
            'unit', 'scale', 'currency' => (string) ($data['unit'] ?? ''),
            default => $value,
        };
        foreach ($nearby as $context) {
            $text = $context['text'];
            if ($missing === 'period' && $context['relationship'] === 'same_table_context'
                && ! preg_match('/\b(?:year|period|fy\s*\d{4}|as\s+at|as\s+of)\b/iu', $text)) {
                continue;
            }
            $supported = $needle !== '' && $this->contains($text, $needle);
            if (! $supported && in_array($missing, ['value', 'scale', 'currency', 'unit'], true)
                && $measurement !== null) {
                $supported = $this->matchingNumbers($measurement, $text, $data) !== [];
            }
            if (! $supported && in_array($missing, ['scale', 'currency', 'unit'], true)) {
                $supported = $this->unitContextSupports($measurement, $text);
            }
            if ($missing === 'value') {
                // An adjacent table row can state a different metric. It needs its own exact label.
                $supported = $supported && $this->contains($text, (string) ($data['label'] ?? ''));
            }
            if ($supported && ($valueInQuote || $quoteMatches !== [] || $missing === 'value')) {
                $base['missing_component'] = $missing;
                $base['supporting_spans'] = [[...$context['span'], 'relationship' => $context['relationship'],
                    'text' => $this->excerpt($text)]];

                return $this->bucket($base, 'surrounding_context_supported',
                    'The missing '.$missing.' is explicit in bounded structural context.');
            }
        }

        if ($reason === 'value_not_in_quote' && $measurement !== null && $quoteMatches === []) {
            return $this->unsupported($base, 'value absent',
                'The stored quantity is absent from the cited quote and bounded structural context.');
        }
        if ($reason === 'period_not_in_quote' && $valueInQuote && ! $periodInQuote && $cited !== null) {
            return $this->unsupported($base, 'period absent',
                'The stored period is absent from the cited quote and bounded structural context.');
        }

        return $base;
    }

    private function failureReason(array $data, string $quote): string
    {
        $value = (string) ($data['value'] ?? '');
        if ($value === '' || $quote === '' || ! $this->contains($quote, $value)) {
            return 'value_not_in_quote';
        }
        $period = (string) ($data['period'] ?? '');
        if ($period !== '' && ! $this->contains($quote, $period)) {
            return 'period_not_in_quote';
        }
        if ($data['due_date'] ?? null) {
            try {
                $validated = EvidenceSchema::validate(['records' => [$data]], $quote);
                if (($validated['records'][0]['due_date'] ?? null) !== $data['due_date']) {
                    return 'due_date_not_grounded';
                }
            } catch (AiProcessingException) {
                return 'due_date_not_grounded';
            }
        }

        return 'other';
    }

    private function contains(string $haystack, string $needle): bool
    {
        $needle = $this->normalizer->normalize($needle);

        return $needle !== '' && str_contains($this->normalizer->normalize($haystack), $needle);
    }

    /** @param array<string,DocumentSourceSpan> $spans */
    private function context(array $record, ?DocumentSourceSpan $cited, array $spans, string $text): array
    {
        if (! $cited) {
            return [];
        }
        $found = [];
        $ordinal = (int) $cited->ordinal;
        foreach ($spans as $span) {
            $delta = (int) $span->ordinal - $ordinal;
            $heading = ($record['section'] ?? null) === $span->span_key && $span->type === 'heading';
            if ($delta === 0 || (! $heading && (abs($delta) > 2 || $span->page !== $cited->page))) {
                continue;
            }
            // Adjacent prose may contain an unrelated fact; only structural spans are support.
            if (! $heading && $span->type !== 'heading'
                && ! ($span->type === 'table_row' && $cited->type === 'table_row')) {
                continue;
            }
            $relation = $heading ? 'heading' : ($cited->type === 'table_row' && $span->type === 'table_row'
                ? 'same_table_context' : ($delta < 0 ? 'previous' : 'next'));
            $found[] = ['span' => $this->spanMetadata($span), 'relationship' => $relation,
                'text' => mb_substr($text, (int) $span->start_offset,
                    max(0, (int) $span->end_offset - (int) $span->start_offset))];
        }

        return $found;
    }

    private function spanMetadata(DocumentSourceSpan $span): array
    {
        return ['id' => $span->span_key, 'type' => $span->type, 'ordinal' => (int) $span->ordinal];
    }

    private function excerpt(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > 320 ? mb_substr($text, 0, 319).'…' : $text;
    }

    private function matchingNumbers(Measurement $stored, string $text, array $data): array
    {
        $matches = $this->numberTokens($text);
        $out = [];
        foreach ($matches as $token) {
            $parsed = $this->measurements->parse(trim($token), null, $data['label'] ?? null);
            if ($parsed && abs($parsed->magnitude - $stored->magnitude)
                <= max(1e-9, abs($stored->magnitude) * 1e-12)) {
                $out[] = $parsed;
            }
        }

        return $out;
    }

    private function unscaledNumberInQuote(Measurement $stored, string $quote, array $data): bool
    {
        if ($stored->scale === 1.0) {
            return false;
        }
        foreach ($this->numberTokens($quote) as $token) {
            $parsed = $this->measurements->parse(trim($token), null, $data['label'] ?? null);
            if ($parsed && $parsed->currency === null && $parsed->scale === 1.0
                && abs($parsed->magnitude * $stored->scale - $stored->magnitude)
                    <= max(1e-9, abs($stored->magnitude) * 1e-12)) {
                return true;
            }
        }

        return false;
    }

    private function numberTokens(string $text): array
    {
        preg_match_all('/(?<![\p{L}\d])(?:[A-Z]{3}\s*|[$€£₹₦]\s*)?-?\d[\d,]*(?:\.\d+)?\s*(?:%|percent|thousand|million|billion|trillion|[kmbt])?(?![\p{L}\d])/iu',
            $text, $matches);

        return $matches[0];
    }

    private function safeEquivalent(?Measurement $stored, array $matches): bool
    {
        if (! $stored) {
            return false;
        }
        foreach ($matches as $match) {
            if ($stored->scale === $match->scale && $stored->currency === $match->currency
                && ($stored->kind === $match->kind || $match->kind === 'unknown')) {
                return true;
            }
        }

        return false;
    }

    private function generatedWording(string $value): bool
    {
        $stripped = preg_replace('/(?:[$€£₹₦]|\b[A-Z]{3}\b|\d[\d,.]*|%|\b(?:percent|thousand|million|billion|trillion|over|about|approximately|cases|people|children)\b)/iu', ' ', $value);

        return count(preg_split('/\s+/u', trim($stripped), -1, PREG_SPLIT_NO_EMPTY)) >= 2;
    }

    private function missingUnitComponent(?Measurement $stored, array $matches): string
    {
        if (! $stored) {
            return 'value';
        }
        $match = $matches[0];
        if ($stored->currency !== $match->currency) {
            return 'currency';
        }
        if ($stored->scale !== $match->scale) {
            return 'scale';
        }

        return 'unit';
    }

    private function unitContextSupports(?Measurement $stored, string $text): bool
    {
        if (! $stored || $stored->currency === null) {
            return false;
        }
        $unit = $this->measurements->parse('1', $text);

        return $unit !== null && $unit->currency === $stored->currency && $unit->scale === $stored->scale;
    }

    private function semanticConflict(string $label, string $quote): bool
    {
        return (bool) preg_match('/\b(?:percentage|share)\s+of\s+total\s+spending\b/iu', $label)
            && ! preg_match('/\btotal\s+spending\b/iu', $quote)
            && (bool) preg_match('/\b(?:spent\s+on|spending\s+on)\s+humanitarian\s+action\b/iu', $quote);
    }

    private function bucket(array $base, string $bucket, string $reason): array
    {
        $base['diagnostic_bucket'] = $bucket;
        $base['diagnostic_reason'] = $reason;

        return $base;
    }

    private function unsupported(array $base, string $disagreement, string $reason): array
    {
        $base['disagreement'] = $disagreement;

        return $this->bucket($base, 'genuinely_unsupported', $reason);
    }
}
