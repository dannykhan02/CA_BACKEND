<?php

namespace App\Services\Intelligence\Materiality;

use App\Models\Document;
use App\Models\DocumentEntity;
use App\Models\DocumentEvidence;
use App\Models\DocumentSourceSpan;
use App\Services\Intelligence\Values\TypedEvidenceProjector;
use Illuminate\Support\Collection;

/** Projects only already-stored rows; never creates or changes a span set. */
class MaterialityReadModel
{
    public function __construct(private TypedEvidenceProjector $projector, private DateRoleResolver $dates) {}

    /** @param Collection<int,DocumentEvidence> $evidence @param list<array<string,mixed>> $charts @param list<string> $cited @return array{records:list<array<string,mixed>>,context:array<string,mixed>} */
    public function build(Document $document, Collection $evidence, array $charts, array $cited): array
    {
        $version = $document->ai_pipeline['extraction_version'] ?? null;
        $spanRows = is_string($version) ? DocumentSourceSpan::where('workspace_id', $document->workspace_id)
            ->where('document_id', $document->id)->where('extraction_version', $version)
            ->orderBy('ordinal')->get() : collect();
        $spans = $spanRows->keyBy('span_key');
        $sections = [];
        $heading = null;
        foreach ($spanRows as $span) {
            if ($span->type === 'heading') {
                $heading = $span->span_key;
            }
            $sections[$span->span_key] = $heading;
        }
        $comparable = [];
        foreach ($charts as $chart) {
            foreach ($chart['sourceIds'] ?? [] as $sourceId) {
                $comparable[$sourceId] = true;
            }
        }
        $risks = $document->risks->keyBy('id');
        $deadlines = $document->deadlines->keyBy('id');
        $entityIds = [];
        foreach (DocumentEntity::where('workspace_id', $document->workspace_id)
            ->where('document_id', $document->id)->orderBy('id')->get(['id', 'normalized_value']) as $entity) {
            $entityIds[$entity->normalized_value] ??= 'entity:'.$entity->id;
        }
        $records = [];
        foreach ($evidence as $row) {
            $data = is_array($row->data) ? $row->data : [];
            $projected = $this->projector->project($row, $entityIds);
            $source = $row->sources[0] ?? [];
            $span = $spans->get($source['span_id'] ?? null);
            $status = null;
            if (is_string($row->source_id)) {
                [$prefix, $id] = array_pad(explode(':', $row->source_id, 2), 2, null);
                $status = match ($prefix) {
                    'risk' => $risks->get($id)?->status,
                    'deadline' => $deadlines->get($id)?->status,
                    default => null,
                };
            }
            $quotes = array_values(array_filter(array_column($row->sources ?? [], 'quote'), 'is_string'));
            $records[] = [
                'identity' => $row->identity, 'source_id' => $row->source_id ?: 'evidence:'.$row->id,
                'kind' => $row->kind, 'data' => $data,
                'typed' => $this->dates->resolve($projected['typed'], $data + ['kind' => $row->kind], $quotes),
                'provenance' => $projected['provenance'], 'sources' => $row->sources ?? [],
                'status' => $status, 'span_type' => $span?->type, 'span_ordinal' => $span?->ordinal,
                'section' => $sections[$source['span_id'] ?? ''] ?? null, 'page' => $source['page'] ?? null,
            ];
        }

        return ['records' => $records, 'context' => [
            'span_count' => $spanRows->isEmpty() ? null : $spanRows->count(),
            'cited_source_ids' => array_fill_keys($cited, true),
            'comparable_source_ids' => $comparable,
        ]];
    }
}
