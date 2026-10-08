<?php

namespace App\Services\Intelligence;

use App\Models\DocumentSourceSpan;
use App\Services\AI\Incremental\EvidenceMerger;
use App\Services\Intelligence\Brief\KeyFigureSelector;

/** Diagnostic-only subtypes, structural candidates and hypothetical selector runs. */
class ProvenanceDiagnosticExtension
{
    private const UNSUPPORTED = ['period_only_missing', 'value_missing', 'semantic_mismatch',
        'unit_or_scale_missing', 'other_unsupported'];

    private const AMBIGUOUS = ['header_context_present_but_unassociated', 'context_elsewhere_untied',
        'context_not_preserved', 'other'];

    public function __construct(private EvidenceMerger $normalizer, private MeasurementParser $measurements,
        private KeyFigureSelector $figures) {}

    /** @param list<array<string,mixed>> $records
     *  @param list<array{record:array<string,mixed>,diagnosis:array<string,mixed>}> $unknown
     *  @param array<string,DocumentSourceSpan> $spans
     */
    public function analyze(array $records, array $unknown, array $spans, string $text,
        \DateTimeImmutable $asOf): array
    {
        $unsupported = array_fill_keys(self::UNSUPPORTED, 0);
        $ambiguous = array_fill_keys(self::AMBIGUOUS, 0);
        $unsupportedRows = [];
        $ambiguousRows = [];
        $localTypes = array_fill_keys(['heading', 'section', 'table_row', 'list_item', 'sentence', 'prose'], 0);
        $recoverable = [];
        $rules = [];

        $prepared = $this->prepareSpans($spans, $text);
        foreach ($unknown as ['record' => $record, 'diagnosis' => $diagnosis]) {
            $identity = (string) ($record['identity'] ?? $record['source_id'] ?? '');
            if ($diagnosis['diagnostic_bucket'] === 'genuinely_unsupported') {
                $row = $this->unsupported($record, $diagnosis);
                $unsupported[$row['unsupported_subtype']]++;
                $unsupportedRows[] = $row;
            } elseif ($diagnosis['diagnostic_bucket'] === 'ambiguous') {
                $row = $this->ambiguous($record, $diagnosis, $prepared, $localTypes);
                $ambiguous[$row['classification']]++;
                $ambiguousRows[] = $row;
                if ($row['classification'] === 'header_context_present_but_unassociated') {
                    $key = $row['deterministic_rule']['description'];
                    $rules[$key] ??= ['rule_description' => $key, 'candidate_source_ids' => [],
                        'source_ids' => [],
                        'missing_components_recovered' => [], 'false_positive_risk' => $row['deterministic_rule']['false_positive_risk'],
                        'stored_deterministic_structure_only' => $row['deterministic_rule']['stored_deterministic_structure_only']];
                    $rules[$key]['candidate_source_ids'][] = $record['source_id'];
                    if ($row['deterministic_rule']['supported']) {
                        $recoverable[$identity] = true;
                        $rules[$key]['source_ids'][] = $record['source_id'];
                        $rules[$key]['missing_components_recovered'] = array_values(array_unique(array_merge(
                            $rules[$key]['missing_components_recovered'], $row['missing_components'])));
                    }
                }
            }
        }
        foreach ($rules as &$rule) {
            $rule['records_recovered'] = count($rule['source_ids']);
        }
        unset($rule);

        $conservative = [];
        $broader = [];
        foreach ($unknown as ['record' => $record, 'diagnosis' => $diagnosis]) {
            $identity = (string) ($record['identity'] ?? $record['source_id'] ?? '');
            $clear = in_array($diagnosis['diagnostic_bucket'], ['numeric_equivalent',
                'surrounding_context_supported'], true);
            if ($clear || isset($recoverable[$identity])) {
                $conservative[$identity] = true;
                $broader[$identity] = true;
            } elseif ($diagnosis['diagnostic_bucket'] === 'extraction_wording_mismatch') {
                $broader[$identity] = true;
            }
        }

        return [
            'genuinely_unsupported' => ['subtype_counts' => $unsupported, 'records' => $unsupportedRows],
            'ambiguous' => ['classification_counts' => $ambiguous, 'records' => $ambiguousRows],
            'structural_spans' => [
                'local_type_occurrences' => $localTypes,
                'stored_fields' => ['id', 'workspace_id', 'document_id', 'extraction_version', 'span_key',
                    'ordinal', 'page', 'start_offset', 'end_offset', 'type', 'created_at'],
                'explicit_table_or_group_id' => false, 'parent_or_header_reference' => false,
                'stored_section_reference' => false, 'shared_heading' => 'derived from nearest preceding heading by MaterialityReadModel',
                'contiguous_table_rows' => 'derivable from adjacent ordinals, type and page; no stored table boundary',
                'page_relationship' => 'stored page number',
            ],
            'candidate_rules' => array_values($rules),
            'projection' => [
                'as_of' => $asOf->format('Y-m-d'),
                'current' => $this->project($records, [], $asOf),
                'conservative' => $this->project($records, $conservative, $asOf),
                'broader_hypothetical' => $this->project($records, $broader, $asOf),
                'extraction_wording_mismatch_is_separate' => true,
                'typed_values' => 'For recovered unknowns, parsed stored values are diagnostic-only hypothetical TypedValues; no row is changed.',
            ],
        ];
    }

    private function unsupported(array $record, array $diagnosis): array
    {
        $reason = $diagnosis['disagreement'] ?? null;
        $subtype = match ($reason) {
            'period absent' => 'period_only_missing',
            'value absent' => 'value_missing',
            'label/evidence semantic mismatch' => 'semantic_mismatch',
            'wrong unit/scale', 'unit absent', 'scale absent', 'currency absent' => 'unit_or_scale_missing',
            default => 'other_unsupported',
        };
        if ($subtype === 'value_missing' && $this->rawNumberMatches($record)) {
            $subtype = 'unit_or_scale_missing';
        }

        return $this->base($record, $diagnosis) + [
            'unsupported_subtype' => $subtype,
            'deterministic_explanation' => match ($subtype) {
                'period_only_missing' => 'The value is cited, but the stored period is absent from the quote and bounded context.',
                'value_missing' => 'The stored quantity is absent from the cited quote and bounded context.',
                'semantic_mismatch' => 'The label describes a different metric from the cited quantitative statement.',
                'unit_or_scale_missing' => 'The number is visible, but its stored unit, currency or scale is not grounded.',
                default => 'The stored evidence is insufficient for a narrower unsupported subtype.',
            },
        ];
    }

    /** @param list<array<string,mixed>> $prepared @param array<string,int> $localTypes */
    private function ambiguous(array $record, array $diagnosis, array $prepared, array &$localTypes): array
    {
        $source = $record['sources'][0] ?? [];
        $citedKey = $source['span_id'] ?? null;
        $cited = null;
        foreach ($prepared as $span) {
            if ($span['id'] === $citedKey && (! isset($source['extraction_version'])
                || $source['extraction_version'] === $span['version'])) {
                $cited = $span;
                break;
            }
        }
        $missing = $this->missingComponents($record, $diagnosis);
        $local = [];
        $candidates = [];
        foreach ($prepared as $span) {
            if ($cited && ($span['id'] === $cited['id'] || abs($span['ordinal'] - $cited['ordinal']) <= 2
                || $span['id'] === ($record['section'] ?? null))) {
                $local[$span['id']] = true;
                $localTypes[$span['type']] = ($localTypes[$span['type']] ?? 0) + 1;
            }
            if ($cited && $span['id'] === $cited['id']) {
                continue;
            }
            $supports = array_values(array_filter($missing,
                fn ($component) => $this->supports($component, $record, $span['text'])));
            if ($supports === []) {
                continue;
            }
            $samePage = $cited && $span['page'] !== null && $span['page'] === $cited['page'];
            $nearby = $cited && abs($span['ordinal'] - $cited['ordinal']) <= 5;
            $candidates[] = [
                'span' => $this->publicSpan($span), 'supports' => $supports,
                'same_page' => (bool) $samePage, 'nearby' => (bool) $nearby,
                'relationship' => $this->relationship($cited, $span, $record, $prepared),
                'text' => $this->excerpt($span['text']),
            ];
        }
        usort($candidates, fn ($a, $b) => ($b['same_page'] <=> $a['same_page'])
            ?: ($b['nearby'] <=> $a['nearby'])
            ?: (abs($a['span']['ordinal'] - ($cited['ordinal'] ?? 0))
                <=> abs($b['span']['ordinal'] - ($cited['ordinal'] ?? 0))));
        $header = null;
        foreach ($candidates as $candidate) {
            if ($candidate['relationship']['header_candidate']) {
                $header = $candidate;
                break;
            }
        }
        $localCandidate = collect($candidates)->first(fn ($candidate) => $candidate['same_page'] || $candidate['nearby']);
        $classification = $header ? 'header_context_present_but_unassociated'
            : ($localCandidate ? 'context_elsewhere_untied' : ($candidates === [] ? 'context_not_preserved' : 'other'));
        $rule = $header ? $this->rule($header, $candidates, $cited, $record, $missing) : null;
        $why = match ($classification) {
            'header_context_present_but_unassociated' => $rule['supported']
                ? 'A unique explicit header precedes the cited row in one uninterrupted same-page table-row run under the same derived heading.'
                : 'Header-like text is nearby, but stored spans do not prove an unambiguous row-to-header relationship.',
            'context_elsewhere_untied' => 'Support appears on the same page or nearby, without a deterministic header or section relationship.',
            'context_not_preserved' => 'No stored source span contains the missing component.',
            default => 'A candidate exists only outside the local page/ordinal context or the structure is indeterminate.',
        };

        return $this->base($record, $diagnosis) + [
            'classification' => $classification, 'missing_components' => $missing,
            'candidate_supporting_spans' => array_slice($candidates, 0, 20),
            'candidate_count' => count($candidates), 'candidate_spans_truncated' => count($candidates) > 20,
            'structural_relationship_explanation' => $why,
            'deterministic_rule' => $rule,
        ];
    }

    private function missingComponents(array $record, array $diagnosis): array
    {
        $data = $record['data'] ?? [];
        $quote = (string) ($data['quote'] ?? $record['sources'][0]['quote'] ?? '');
        $components = [];
        if (($diagnosis['original_reason'] ?? null) === 'value_not_in_quote') {
            $components[] = 'value';
            if ($this->rawNumberMatches($record)) {
                $measurement = $this->measurements->parse((string) ($data['value'] ?? ''),
                    $data['unit'] ?? null, $data['label'] ?? null);
                if ($measurement?->currency !== null) {
                    $components[] = 'currency';
                }
                if ($measurement?->scale !== 1.0) {
                    $components[] = 'scale';
                }
            }
        }
        if (($data['period'] ?? null) && ! $this->contains($quote, (string) $data['period'])) {
            $components[] = 'period';
        }
        if (($diagnosis['original_reason'] ?? null) === 'due_date_not_grounded') {
            $components[] = 'due_date';
        }

        return array_values(array_unique($components));
    }

    private function supports(string $component, array $record, string $text): bool
    {
        $data = $record['data'] ?? [];
        $measurement = $this->measurements->parse((string) ($data['value'] ?? ''),
            $data['unit'] ?? null, $data['label'] ?? null);

        return match ($component) {
            'value' => $this->contains($text, (string) ($data['value'] ?? '')),
            'period' => $this->contains($text, (string) ($data['period'] ?? '')),
            'due_date' => $this->contains($text, (string) ($data['due_date'] ?? '')),
            'currency' => $measurement?->currency !== null && $this->contains($text, $measurement->currency),
            'scale' => $measurement !== null && $measurement->scale !== 1.0
                && $this->measurements->parse('1', $text)?->scale === $measurement->scale,
            default => false,
        };
    }

    /** @param list<array<string,mixed>> $prepared */
    private function relationship(?array $cited, array $candidate, array $record, array $prepared): array
    {
        if (! $cited) {
            return ['header_candidate' => false, 'same_derived_heading' => false,
                'contiguous_table_run' => false];
        }
        $sameHeading = $candidate['heading'] !== null && $candidate['heading'] === ($record['section'] ?? null);
        $run = $candidate['type'] === 'table_row' && $cited['type'] === 'table_row'
            && $candidate['page'] !== null && $candidate['page'] === $cited['page']
            && $candidate['ordinal'] < $cited['ordinal'] && $sameHeading;
        if ($run) {
            $betweenCount = 0;
            foreach ($prepared as $between) {
                if ($between['ordinal'] > $candidate['ordinal'] && $between['ordinal'] < $cited['ordinal']
                    && ($between['type'] !== 'table_row' || $between['page'] !== $cited['page'])) {
                    $run = false;
                    break;
                }
                if ($between['ordinal'] > $candidate['ordinal'] && $between['ordinal'] < $cited['ordinal']) {
                    $betweenCount++;
                }
            }
            $run = $run && $betweenCount === $cited['ordinal'] - $candidate['ordinal'] - 1;
        }
        $headerStyle = (bool) preg_match('/\b(?:year|period|fy\s*\d{4}|currency|units?|amounts?|values?)\b/iu',
            $candidate['text']) || ($this->measurements->parse('1', $candidate['text'])?->currency !== null);
        $header = ($candidate['type'] === 'heading' && $sameHeading)
            || ($run && $headerStyle);

        return ['header_candidate' => $header, 'same_derived_heading' => $sameHeading,
            'contiguous_table_run' => $run, 'header_style' => $headerStyle];
    }

    private function rule(array $header, array $candidates, ?array $cited, array $record,
        array $missing): array
    {
        $relationship = $header['relationship'];
        $headerCandidates = array_values(array_filter($candidates,
            fn ($candidate) => $candidate['relationship']['header_candidate']));
        $supported = $cited !== null && $relationship['contiguous_table_run']
            && $relationship['same_derived_heading'] && count($headerCandidates) === 1;
        $structureSupported = $supported;
        foreach ($missing as $component) {
            $resolved = $component === 'value'
                ? ($this->rawNumberMatches($record)
                    && in_array('currency', $header['supports'], true)
                    && in_array('scale', $header['supports'], true))
                : in_array($component, $header['supports'], true);
            if (! $resolved) {
                $supported = false;
            }
        }
        $heading = (string) ($record['section'] ?? 'none');
        $description = $header['span']['type'] === 'heading'
            ? 'The cited row must have an explicit stored link to heading '.$header['span']['id']
                .' that supplies every missing component; a derived section alone does not prove table-header scope.'
            : 'A cited table_row shares page and derived heading '.$heading
                .' with a unique explicit header row in the same uninterrupted preceding table_row run.';

        return [
            'supported' => $supported,
            'description' => $description,
            'candidate_span_ids' => [$header['span']['id']],
            'false_positive_risk' => $supported
                ? 'Residual: adjacent tables without a separator or table ID could be merged.'
                : ($structureSupported ? 'High: the candidate header does not state every missing component.'
                    : 'High: no unique uninterrupted table-row/header association is stored.'),
            'stored_deterministic_structure_only' => $header['span']['type'] !== 'heading',
        ];
    }

    /** @param array<string,DocumentSourceSpan> $spans */
    private function prepareSpans(array $spans, string $text): array
    {
        $rows = array_values($spans);
        usort($rows, fn ($a, $b) => $a->ordinal <=> $b->ordinal);
        $prepared = [];
        $heading = null;
        foreach ($rows as $span) {
            if ($span->type === 'heading') {
                $heading = $span->span_key;
            }
            $prepared[] = ['id' => $span->span_key, 'type' => $span->type,
                'ordinal' => (int) $span->ordinal, 'page' => $span->page === null ? null : (int) $span->page,
                'version' => $span->extraction_version, 'heading' => $heading,
                'text' => mb_substr($text, (int) $span->start_offset,
                    max(0, (int) $span->end_offset - (int) $span->start_offset))];
        }

        return $prepared;
    }

    private function project(array $records, array $recover, \DateTimeImmutable $asOf): array
    {
        $projected = [];
        $metrics = 0;
        $origin = 0;
        foreach ($records as $record) {
            if (($record['kind'] ?? null) === 'metric') {
                $metrics++;
                $identity = (string) ($record['identity'] ?? $record['source_id'] ?? '');
                if (isset($recover[$identity])) {
                    $record['provenance']['origin'] = 'document';
                    $record['provenance']['assertion'] = 'stated';
                    $this->hypotheticalTypedValue($record);
                }
                $origin += ($record['provenance']['origin'] ?? null) === 'document' ? 1 : 0;
            }
            $projected[] = $record;
        }
        // The production selector caps its output at six. Singleton runs use its actual
        // eligibility gate to count all eligible records without changing that cap or config.
        $eligible = 0;
        foreach ($projected as $record) {
            if (($record['kind'] ?? null) === 'metric' && $this->figures->select([$record], $asOf) !== []) {
                $eligible++;
            }
        }

        return ['document_origin' => $origin, 'document_origin_percentage' => $metrics
            ? round(100 * $origin / $metrics, 2) : 0,
            'unknown_origin' => $metrics - $origin,
            'key_figure_eligible_count' => $eligible,
            'selected_source_ids_capped_at_six' => array_column($this->figures->select($projected, $asOf), 'source_id'),
        ];
    }

    private function hypotheticalTypedValue(array &$record): void
    {
        if (is_array($record['typed']['value'] ?? null)) {
            return;
        }
        $data = $record['data'] ?? [];
        $value = (string) ($data['value'] ?? '');
        $parsed = $this->measurements->parse($value, $data['unit'] ?? null, $data['label'] ?? null);
        if (! $parsed) {
            return;
        }
        $record['typed']['value'] = ['type' => $parsed->kind === 'currency' ? 'money' : $parsed->kind,
            'unit_kind' => $parsed->kind, 'currency' => $parsed->currency,
            'number' => $parsed->magnitude, 'scale' => $parsed->scale,
            'raw' => $value, 'unit' => $data['unit'] ?? null, 'precision' => 'exact'];
    }

    private function rawNumberMatches(array $record): bool
    {
        $data = $record['data'] ?? [];
        $stored = $this->measurements->parse((string) ($data['value'] ?? ''),
            $data['unit'] ?? null, $data['label'] ?? null);
        if (! $stored || $stored->scale === 1.0) {
            return false;
        }
        $quote = (string) ($data['quote'] ?? $record['sources'][0]['quote'] ?? '');
        preg_match_all('/(?<!\d)\d[\d,]*(?:\.\d+)?(?!\d)/u', $quote, $tokens);
        foreach ($tokens[0] as $token) {
            $candidate = $this->measurements->parse($token, null, $data['label'] ?? null);
            if ($candidate && abs($candidate->magnitude * $stored->scale - $stored->magnitude)
                <= max(1e-9, abs($stored->magnitude) * 1e-12)) {
                return true;
            }
        }

        return false;
    }

    private function base(array $record, array $diagnosis): array
    {
        $diagnosis['cited_quote'] = (string) ($record['data']['quote'] ?? $record['sources'][0]['quote'] ?? '');

        return $diagnosis;
    }

    private function publicSpan(array $span): array
    {
        return ['id' => $span['id'], 'type' => $span['type'], 'ordinal' => $span['ordinal'],
            'page' => $span['page']];
    }

    private function contains(string $haystack, string $needle): bool
    {
        $needle = $this->normalizer->normalize($needle);

        return $needle !== '' && str_contains($this->normalizer->normalize($haystack), $needle);
    }

    private function excerpt(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > 320 ? mb_substr($text, 0, 319).'…' : $text;
    }
}
