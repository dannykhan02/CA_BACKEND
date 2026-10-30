<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Services\Comparisons\ComparisonFactEvidence;
use App\Services\Comparisons\SourceIdentityIdentifier;
use App\Services\DocumentIntelligenceService;
use App\Support\DeadlineClassification;

/** A read projection of published intelligence. No extraction, persistence or identity inference. */
class PublishedIntelligenceProjection
{
    public const VERSION = 'published-intelligence-m3-v1';

    public function read(Document $document): array
    {
        $document = app(DocumentIntelligenceService::class)->loadIntelligence($document);
        $key = $document->ai_pipeline['key'] ?? null;
        $records = $key === null ? collect() : DocumentEvidence::where('workspace_id', $document->workspace_id)
            ->where('document_id', $document->id)->where('pipeline_key', $key)->orderBy('identity')->get();
        $targets = [];
        foreach (['kpis' => 'kpi:', 'risks' => 'risk:', 'deadlines' => 'deadline:', 'entities' => 'entity:'] as $relation => $prefix) {
            foreach ($document->$relation as $item) {
                $targets[$prefix.$item->id] = true;
            }
        }
        $excluded = 0;
        $records = $records->filter(function ($row) use ($targets, &$excluded) {
            if (is_string($row->source_id) && preg_match('/^(?:kpi|risk|deadline|entity):/', $row->source_id)
                && ! isset($targets[$row->source_id])) {
                $excluded++;

                return false;
            }

            return true;
        });
        $metrics = app(MetricCollector::class)->collect($document, $records);
        $evidence = app(ComparisonFactEvidence::class)->read($document, $records);
        $facts = $this->facts($document, $metrics, $evidence);
        $coverage = $this->coverage($facts);

        return ['document' => $document, 'metrics' => $metrics, 'evidenceRecords' => $records, 'facts' => $facts,
            'coverage' => ['version' => self::VERSION, 'revision_id' => $document->published_intelligence_revision_id,
                ...$coverage, 'excluded' => ['missing_revision_bound_source_target' => $excluded], 'timeline_records' => $document->deadlines->count(),
                'eligible_kpis' => count(array_filter($metrics['observations'], fn ($o) => $o->integrity['analyticalEligible'])),
                'limitations' => ['Obligations share deadline source identities. Counts by category are disjoint; Timeline overlaps obligations.',
                    'Narratives and other prose are context, not verified substantive comparisons.']]];
    }

    private function facts(Document $document, array $collected, array $evidence): array
    {
        $observations = collect($collected['observations'])->keyBy('sourceId');
        $factEvidence = app(ComparisonFactEvidence::class);
        $result = [];
        foreach (['deadlines' => 'title', 'risks' => 'title', 'entities' => 'entity_type', 'kpis' => 'label'] as $kind => $label) {
            foreach ($document->$kind as $item) {
                $value = match ($kind) {
                    'deadlines' => ['description' => $item->description, 'due_date' => $item->due_date?->toDateString(), 'relative_text' => $item->relative_text, 'date_type' => $item->date_type],
                    'risks' => ['description' => $item->description, 'severity' => $item->severity],
                    'entities' => ['value' => $item->normalized_value ?: $item->value],
                    'kpis' => ['value' => $item->value, 'unit' => $item->unit, 'period' => $item->period],
                };
                $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $item->$label)));
                $observation = $kind === 'kpis' ? $observations->get('kpi:'.$item->id) : null;
                $context = $kind === 'kpis' ? ['subject' => $observation?->subject,
                    // A source-verified owner name is not a cross-document entity identity.
                    'reported_owner' => $observation?->integrity['governingOwner'] ?? null,
                    'owner' => null,
                    'value_basis' => $observation?->valueBasis, 'aggregation' => $observation?->aggregation,
                    'metric_status' => $observation?->valueStatus(),
                    'analytical_eligible' => ($observation?->integrity['analyticalEligible'] ?? false)
                        && $observation->rawValue === (string) $item->value
                        && trim($observation->measurement->unitText) === trim((string) $item->unit),
                    'verification' => ($observation?->integrity['sourceVerification'] ?? null) === 'verified' ? 'source_verified' : 'unverified'] : [];
                $sourceId = match ($kind) {
                    'kpis' => 'kpi:', 'entities' => 'entity:', 'deadlines' => 'deadline:', 'risks' => 'risk:'
                }.$item->id;
                $entries = $evidence[$sourceId] ?? [];
                $spans = array_merge(...array_map(fn ($entry) => $entry['spans'], $entries));
                $context += $factEvidence->context($kind, $entries);
                if ($kind === 'kpis') {
                    $context['concept_id'] = $item->identityKey($normalized);
                    $context['reporting_scope'] = $factEvidence->reportingScope($spans);
                    $context['accounting_basis'] = $entries[0]['data']['accounting_basis'] ?? null;
                    $context['subject_is_owner'] = ! empty($context['reported_owner'])
                        && mb_strtolower(trim((string) $context['subject'])) === mb_strtolower(trim($context['reported_owner']));
                } elseif ($kind === 'deadlines') {
                    $context['deadline_type'] = $item->deadline_type;
                } elseif ($kind === 'entities') {
                    $context['entity_type'] = $item->entity_type;
                }
                $namedOwner = $kind === 'kpis' ? ($context['reported_owner'] ?? '') : ($kind === 'entities' ? $item->value : '');
                if ($namedOwner !== '' && ($kind !== 'kpis' || $context['verification'] === 'source_verified')) {
                    $identifier = app(SourceIdentityIdentifier::class)->forName($namedOwner, $spans);
                    if ($identifier !== null) {
                        $context['organization_identifier'] = $identifier;
                    }
                }
                $key = $kind.':'.($kind === 'kpis' ? $item->identityKey($normalized) : $normalized);
                $result[$key][] = ['category' => $kind, 'label' => $item->$label, 'value' => $value, 'context' => $context,
                    'source' => ['document_id' => $document->id, 'document_name' => $document->name,
                        'intelligence_revision_id' => $document->published_intelligence_revision_id,
                        'source_id' => $sourceId, 'evidence_ids' => array_column($entries, 'id'), 'spans' => $spans,
                        'insight_id' => $item->id, 'page_number' => $observation?->page,
                        'location_kind' => $observation?->page !== null ? 'page' : 'unknown',
                        'evidence' => $observation?->sourceContext ?: ($item->evidence ?? $item->context)]];
            }
        }
        // Parser-owned PDF facts have stable evidence IDs but no derived KPI row.
        // Preserve those existing observations instead of silently losing them at this adapter.
        foreach ($observations as $observation) {
            if (! str_starts_with($observation->sourceId, 'pdf-cell:')) {
                continue;
            }
            $entries = $evidence[$observation->sourceId] ?? [];
            if (count($entries) !== 1) {
                continue;
            }
            $data = $entries[0]['data'];
            $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $observation->label)));
            $concept = $observation->definitionId !== null ? 'definition:'.$observation->definitionId : 'label:'.$normalized;
            $owner = $observation->integrity['governingOwner'] ?? null;
            $spans = $entries[0]['spans'];
            $context = ['concept_id' => $concept, 'subject' => $observation->subject, 'reported_owner' => $owner, 'owner' => null,
                'reporting_scope' => $factEvidence->reportingScope($spans),
                'subject_is_owner' => $owner !== null && mb_strtolower(trim($owner)) === mb_strtolower(trim($observation->subject)),
                'value_basis' => $observation->valueBasis, 'aggregation' => $observation->aggregation,
                'metric_status' => $observation->valueStatus(), 'accounting_basis' => $data['accounting_basis'] ?? null,
                'analytical_eligible' => $observation->integrity['analyticalEligible'] ?? false,
                'verification' => ($observation->integrity['sourceVerification'] ?? null) === 'verified' ? 'source_verified' : 'unverified'];
            $context['quantity_kind'] = $observation->quantityKind;
            if (($data['comparison_statement'] ?? null) === 'consolidated_income_statement'
                && $context['verification'] === 'source_verified' && $normalized === 'operating income') {
                $context['measurement_nature'] = 'flow';
                $context['aggregation'] = $context['aggregation'] ?: 'total';
                $context['requires_accounting_basis'] = true;
            }
            if ($owner !== null && $context['verification'] === 'source_verified') {
                $identifier = app(SourceIdentityIdentifier::class)->forName($owner, $spans);
                if ($identifier !== null) {
                    $context['organization_identifier'] = $identifier;
                }
            }
            $result['kpis:'.$concept][] = ['category' => 'kpis', 'label' => $observation->label,
                'value' => ['value' => $data['value'] ?? $observation->rawValue, 'unit' => $data['unit'] ?? null, 'period' => $data['period'] ?? null],
                'context' => $context, 'source' => ['document_id' => $document->id, 'document_name' => $document->name,
                    'intelligence_revision_id' => $document->published_intelligence_revision_id,
                    'source_id' => $observation->sourceId, 'evidence_ids' => array_column($entries, 'id'), 'spans' => $spans,
                    'page_number' => $observation->page, 'location_kind' => $observation->page === null ? 'unknown' : 'page',
                    'evidence' => $observation->sourceContext]];
        }

        return $this->complete($document, $result, $collected, $evidence);
    }

    private function complete(Document $document, array $result, array $collected, array $evidence): array
    {
        $facts = [];
        $models = [];
        foreach (['kpis' => 'kpi:', 'deadlines' => 'deadline:', 'risks' => 'risk:', 'entities' => 'entity:'] as $relation => $prefix) {
            foreach ($document->$relation as $item) {
                $models[$prefix.$item->id] = $item;
            }
        }
        $observed = collect($collected['observations'])->keyBy('sourceId');
        $seen = [];
        foreach ($result as $rows) {
            foreach ($rows as $row) {
                $id = $row['source']['source_id'];
                $seen[$id] = true;
                $item = $models[$id] ?? null;
                $entries = $evidence[$id] ?? [];
                if ($item !== null) {
                    $row['subtype'] = $item->deadline_type ?? $item->risk_type ?? $item->entity_type;
                    $row['original_title'] = $item->title ?? $item->label ?? $item->value;
                    $row['extraction'] = array_filter($item->only(['confidence', 'provider', 'model', 'prompt_version']), fn ($value) => $value !== null);
                    if ($row['category'] === 'deadlines') {
                        $classification = DeadlineClassification::classify($item->deadline_type, $item->date_type, $item->due_date?->toDateString());
                        $row['context']['classification'] = $classification;
                        if ($classification === 'obligation') {
                            $row['category'] = 'obligations';
                        }
                        $row['views'] = $classification === 'obligation' ? ['obligations', 'deadlines'] : ['deadlines'];
                    }
                    if ($row['category'] === 'risks') {
                        $row['context'] += app(ComparisonFactEvidence::class)->supportedFields($entries, ['subject' => 'subject', 'reference' => 'risk_reference']);
                        $row['context']['risk_type'] = $item->risk_type;
                        $rawSeverity = array_values(array_filter(array_map(fn ($e) => $e['data']['severity'] ?? null, $entries), fn ($s) => $s !== null));
                        $row['context']['severity_scale'] = count(array_unique($rawSeverity)) <= 1
                            && ($rawSeverity === [] || SeverityNormalizer::normalize($rawSeverity[0]) !== null) ? 'low_medium_high_critical' : 'unknown';
                        $row['origins']['severity'] = 'ai_extracted_classification';
                    }
                    if ($row['category'] === 'entities') {
                        $row['context'] += app(ComparisonFactEvidence::class)->supportedFields($entries, ['role' => 'role']);
                        $row['original_value'] = $item->value;
                    }
                }
                $observation = $observed->get($id);
                if ($observation !== null) {
                    // Exact decimal strings cross the JSON boundary unchanged; chart floats do not.
                    $measurement = $observation->measurement;
                    $row['structured_value'] = ['measurement' => ['exact_magnitude' => $measurement->exactMagnitude,
                        'kind' => $measurement->kind, 'family' => $measurement->family, 'currency' => $measurement->currency,
                        'scale' => (string) $measurement->scale, 'unit' => $measurement->unitText,
                        'canonical_unit' => $measurement->canonicalUnit, 'numeric_verified' => $measurement->numericVerified],
                        'period' => $observation->period === null ? null : [
                            'granularity' => $observation->period->granularity, 'basis' => $observation->period->basis,
                            'label' => $observation->period->label, 'start_date' => $observation->period->startDate, 'end_date' => $observation->period->endDate]];
                    $row['limitations'] = $observation->integrity['reasons'] ?? [];
                    $row['context']['quantity_kind'] = $observation->quantityKind;
                }
                $facts[] = $this->decorate($document, $row, $entries);
            }
        }
        // Evidence-only metrics and other accepted findings are already used by Research Studio.
        // A deleted typed target must never be resurrected from its residual evidence row.
        foreach ($evidence as $id => $entries) {
            if (isset($seen[$id]) || preg_match('/^(?:kpi|risk|deadline|entity):/', $id)) {
                continue;
            }
            $entry = $entries[0];
            $data = $entry['data'];
            $kind = $entry['kind'];
            if ($kind === 'normal_source') {
                continue;
            }
            $category = $kind === 'metric' ? 'kpis' : 'other_findings';
            $observation = $observed->get($id);
            $context = [];
            if ($category === 'kpis') {
                $context = ['concept_id' => $observation?->definitionId !== null ? 'definition:'.$observation->definitionId : 'label:'.$this->normalized($data['label'] ?? ''),
                    'subject' => $observation?->subject ?? ($data['subject'] ?? null), 'owner' => null,
                    'reported_owner' => $observation?->integrity['governingOwner'] ?? null,
                    'analytical_eligible' => $observation?->integrity['analyticalEligible'] ?? false,
                    'verification' => ($observation?->integrity['sourceVerification'] ?? null) === 'verified' ? 'source_verified' : 'unverified',
                    'value_basis' => $observation?->valueBasis, 'aggregation' => $observation?->aggregation,
                    'metric_status' => $observation?->valueStatus(), 'quantity_kind' => $observation?->quantityKind,
                    'reporting_scope' => app(ComparisonFactEvidence::class)->reportingScope(array_merge(...array_column($entries, 'spans'))),
                    'accounting_basis' => $data['accounting_basis'] ?? null];
            }
            $facts[] = $this->decorate($document, ['category' => $category, 'subtype' => $kind,
                'label' => $data['label'] ?? $kind, 'value' => $category === 'kpis'
                    ? ['value' => $data['value'] ?? null, 'unit' => $data['unit'] ?? null, 'period' => $data['period'] ?? null]
                    : ['description' => $data['value'] ?? $data['quote'] ?? null],
                'context' => $context, 'limitations' => $observation?->integrity['reasons'] ?? ($category === 'kpis' ? ['invalid_numeric_value'] : ['context_only']),
                'source' => ['document_id' => $document->id, 'document_name' => $document->name, 'source_id' => $id,
                    'intelligence_revision_id' => $document->published_intelligence_revision_id,
                    'evidence' => $data['quote'] ?? null]], $entries);
        }
        if ($document->intelligenceSummary !== null) {
            $summary = $document->intelligenceSummary;
            $facts[] = $this->decorate($document, ['category' => 'summaries', 'label' => 'Published summary',
                'value' => ['description' => $summary->executive_summary], 'context' => ['cited_source_ids' => app(ImportantFindingsBuilder::class)->citedSourceIds($summary)],
                'structured_value' => $summary->only(['key_findings', 'critical_risks', 'upcoming_deadlines', 'important_entities',
                    'recommended_attention', 'executive_assessment', 'material_findings', 'trends', 'tensions', 'questions']),
                'limitations' => ['synthesized_context_only'],
                'source' => ['document_id' => $document->id, 'document_name' => $document->name,
                    'source_id' => 'summary:'.$summary->id, 'intelligence_revision_id' => $document->published_intelligence_revision_id,
                    'evidence' => null]], []);
        }
        $out = [];
        foreach ($facts as $fact) {
            $key = $fact['category'].':'.($fact['context']['concept_id'] ?? $this->normalized($fact['label']));
            $out[$key][] = $fact;
        }
        ksort($out);
        foreach ($out as &$rows) {
            usort($rows, fn ($a, $b) => strcmp(json_encode($a['value']), json_encode($b['value'])) ?: strcmp($a['source']['source_id'], $b['source']['source_id']));
        }
        unset($rows);

        return $out;
    }

    private function decorate(Document $document, array $row, array $entries): array
    {
        $spans = array_merge(...array_column($entries, 'spans'));
        $row['source']['evidence_ids'] = array_column($entries, 'id');
        $row['source']['spans'] = $spans;
        $pages = array_values(array_unique(array_filter(array_column($spans, 'page'), fn ($p) => is_int($p) && $p > 0)));
        $row['source']['page_number'] ??= count($pages) === 1 ? $pages[0] : null;
        $row['source']['location_kind'] = $row['source']['page_number'] !== null ? 'page' : 'unknown';
        $row['source']['text_ranges_available'] = $spans !== [];
        $row['source']['record_type'] = explode(':', $row['source']['source_id'], 2)[0];
        $row['source']['record_id'] = explode(':', $row['source']['source_id'], 2)[1] ?? null;
        $row['observation_id'] = $document->id.':'.($document->published_intelligence_revision_id ?? 'legacy').':'.$row['source']['source_id'];
        $row['context']['projection_version'] = self::VERSION;
        $row['extracted_data'] = array_column($entries, 'data');
        $row['source_reported_context'] = array_column($spans, 'quote');
        $row['origins']['observation'] = str_starts_with($row['source']['source_id'], 'pdf-cell:') ? 'deterministic_source_projection' : ($entries === [] ? 'stored_extraction_origin_unrecorded' : 'ai_extracted');
        if ($row['category'] === 'obligations') {
            $row['origins']['classification'] = 'deterministic_from_extracted_deadline_type';
        }
        $row['limitations'] ??= [];
        if (count($entries) > 1) {
            $row['context']['ambiguous_source_context'] = true;
            $row['limitations'][] = 'multiple_evidence_records_for_source';
        }
        if (in_array(false, array_column($entries, 'source_current'), true)) {
            $row['limitations'][] = 'stale_source_evidence';
        }
        if ($row['category'] === 'summaries') {
            $row['origins']['observation'] = 'ai_synthesized';
        }
        if ($spans === []) {
            $row['limitations'][] = 'validated_source_spans_unavailable';
        }
        if ($document->ai_pipeline['partial'] ?? false) {
            $row['limitations'][] = 'partial_analysis';
        }

        return $row;
    }

    public function coverage(array $facts): array
    {
        $counts = array_fill_keys(['kpis', 'deadlines', 'obligations', 'risks', 'entities', 'summaries', 'other_findings'], 0);
        $sources = [];
        $grounded = 0;
        $limitations = [];
        foreach ($facts as $rows) {
            foreach ($rows as $row) {
                $counts[$row['category']]++;
                $sources[$row['source']['source_id']] = true;
                $grounded += (int) ! empty($row['source']['spans']);
                foreach ($row['limitations'] ?? [] as $code) {
                    $limitations[$code] = ($limitations[$code] ?? 0) + 1;
                }
            }
        }
        ksort($limitations);

        return ['counts' => $counts, 'unique_sources' => count($sources), 'observations_with_validated_spans' => $grounded,
            'limitation_counts' => $limitations];
    }

    private function normalized(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)));
    }
}
