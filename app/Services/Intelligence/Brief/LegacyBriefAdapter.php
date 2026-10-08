<?php

namespace App\Services\Intelligence\Brief;

use App\Models\Document;
use App\Models\DocumentDeadline;
use App\Models\DocumentEntity;
use App\Models\DocumentKpi;
use App\Models\DocumentRisk;
use App\Services\AI\Incremental\EvidenceMerger;
use App\Services\Documents\EvidencePageLocator;
use App\Services\Intelligence\Materiality\DateRoleResolver;
use App\Services\Intelligence\Values\ValueParser;

/** Normal-route rows have no evidence offsets; preserve that limit while deriving a Brief. */
class LegacyBriefAdapter
{
    public function __construct(private ValueParser $values, private DateRoleResolver $dates,
        private EvidenceMerger $normalizer, private EvidencePageLocator $pages) {}

    /** @return list<array<string,mixed>> */
    public function records(Document $document): array
    {
        $records = [];
        $scope = static fn ($query) => $query->where('workspace_id', $document->workspace_id)
            ->where('document_id', $document->id)->orderBy('id')->get();
        foreach ($scope(DocumentEntity::query()) as $row) {
            $records[] = $this->record($document, 'entity', $row->id, [
                'label' => $row->value, 'value' => $row->value, 'subject' => '',
                'aliases' => [], 'kind' => 'entity',
            ], $row->context, null);
        }
        foreach ($scope(DocumentRisk::query()) as $row) {
            $records[] = $this->record($document, 'risk', $row->id, [
                'label' => $row->title, 'value' => $row->description, 'subject' => '',
                'severity' => $row->severity, 'kind' => 'risk',
            ], $row->evidence, $row->status);
        }
        foreach ($scope(DocumentDeadline::query()) as $row) {
            $kind = $row->deadline_type === 'obligation' ? 'obligation' : 'deadline';
            $records[] = $this->record($document, $kind, $row->id, [
                'label' => $row->title, 'value' => $row->relative_text ?: $row->description,
                'subject' => '', 'kind' => $kind, 'date_type' => $row->date_type,
                'due_date' => $row->due_date?->format('Y-m-d'),
            ], $row->evidence, $row->status);
        }
        foreach ($scope(DocumentKpi::query()) as $row) {
            $records[] = $this->record($document, 'metric', $row->id, [
                'label' => $row->label, 'value' => $row->value, 'unit' => $row->unit,
                'period' => $row->period, 'subject' => '', 'kind' => 'metric',
            ], null, null);
        }

        return $records;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function record(Document $document, string $kind, int|string $id, array $data,
        ?string $quote, ?string $status): array
    {
        $quotes = is_string($quote) && $quote !== '' ? [$quote] : [];
        $typed = $this->dates->resolve($this->values->parse($data, $quotes), $data, $quotes);
        $value = $this->normalizer->normalize((string) ($data['value'] ?? ''));
        $normalQuote = $this->normalizer->normalize((string) $quote);
        $period = $this->normalizer->normalize((string) ($data['period'] ?? ''));
        $hasDate = empty($data['due_date']) || isset($typed['dates']['due_date']['date']);
        $direct = $value !== '' && $normalQuote !== '' && str_contains($normalQuote, $value)
            && ($period === '' || str_contains($normalQuote, $period)) && $hasDate;
        $origin = $direct ? 'document' : 'unknown';
        $page = $this->pages->locate($document->extracted_text, (int) $document->pages, $quote);

        return ['identity' => 'legacy:'.$kind.':'.$id, 'source_id' => match ($kind) {
            'metric' => 'kpi:'.$id, 'obligation', 'deadline' => 'deadline:'.$id,
            default => $kind.':'.$id,
        }, 'kind' => $kind, 'data' => $data, 'typed' => $typed,
            'provenance' => ['origin' => $origin, 'assertion' => $direct ? 'stated' : 'unspecified',
                'attribution' => ['speaker' => null, 'role' => 'unattributed',
                    'reported' => false, 'evidence_ref' => null]],
            'sources' => [], 'status' => $status, 'span_type' => null,
            'span_ordinal' => null, 'section' => null, 'page' => $page];
    }
}
