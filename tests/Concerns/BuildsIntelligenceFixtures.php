<?php

namespace Tests\Concerns;

use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Models\DocumentIntelligenceSummary;
use App\Models\DocumentKpi;
use App\Models\DocumentRisk;
use App\Models\User;
use App\Services\AI\Incremental\EvidenceMerger;
use App\Services\WorkspaceService;
use Illuminate\Support\Str;

/**
 * Stored-evidence fixtures in exactly the shape the incremental pipeline persists: the same
 * `document_evidence.data` record the extraction schema validates, the same `sources` links the
 * merge writes, and the same `document_kpis` observation per metric finding with its `kpi:<id>`
 * reference. Nothing here invents a field the real pipeline does not write, so the analysis layer
 * is exercised against realistic data rather than against its own assumptions.
 */
trait BuildsIntelligenceFixtures
{
    protected function intelligenceDocument(string $name = 'Annual report.pdf', string $status = 'Ready', bool $incremental = true, int $year = 2024): Document
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user = $user->fresh();

        $document = Document::create([
            'workspace_id' => $user->current_workspace_id,
            'uploaded_by' => $user->id,
            'name' => $name,
            'type' => 'PDF',
            'status' => $status,
            'classification' => 'Internal',
            'size_kb' => 2048,
            'year' => $year,
            'pages' => 120,
            'file_hash' => hash('sha256', $name.$user->id),
            'extracted_text' => "Annual report\fFinancing summary\fSector detail",
        ]);
        // ai_pipeline is guarded on the model, exactly as the pipeline itself writes it.
        if ($incremental) {
            $document->forceFill(['ai_pipeline' => ['route' => 'incremental', 'key' => 'pk-test', 'synthesis' => 'completed']])->save();
        }

        return $document->fresh();
    }

    /**
     * One accepted metric finding, persisted the way EvidenceMerger does it: a `document_kpis`
     * observation plus the evidence row that references it.
     *
     * @param  array<string,mixed>  $overrides
     */
    protected function metricFinding(Document $document, string $label, string $value, ?string $unit, ?string $period, array $overrides = []): DocumentKpi
    {
        $record = $overrides + [
            'label' => $label, 'value' => $value, 'subject' => '', 'quote' => $label.': '.$value,
            'reference' => '', 'kind' => 'metric', 'confidence' => 0.9, 'unit' => $unit, 'period' => $period,
            'entity_type' => null, 'date_type' => null, 'due_date' => null, 'severity' => null,
            'metric_type' => null, 'value_basis' => null, 'aggregation' => null, 'quantity_kind' => null,
            'aliases' => [],
        ];

        $kpi = DocumentKpi::create([
            'document_id' => $document->id, 'workspace_id' => $document->workspace_id,
            'label' => $label, 'period' => $period, 'value' => $value, 'unit' => $unit,
            'value_numeric' => is_numeric($number = rtrim(str_replace(',', '', $value), '%')) ? (float) $number : null,
            'identity_metadata' => ['scope' => $record['subject'], 'metric_type' => $record['metric_type'],
                'value_basis' => $record['value_basis'], 'aggregation' => $record['aggregation'],
                'quantity_kind' => $record['quantity_kind']],
        ]);

        $this->evidenceRow($document, 'metric', $record, 'kpi:'.$kpi->id);

        return $kpi;
    }

    /** @param array<string,mixed> $record */
    protected function evidenceRow(Document $document, string $kind, array $record, ?string $sourceId, ?int $page = 14): DocumentEvidence
    {
        $record += ['subject' => '', 'quote' => ($record['label'] ?? '').': '.($record['value'] ?? ''), 'confidence' => 0.9,
            'unit' => null, 'period' => null, 'entity_type' => null, 'date_type' => null, 'due_date' => null,
            'severity' => null, 'metric_type' => null, 'value_basis' => null, 'aggregation' => null,
            'quantity_kind' => null, 'aliases' => [], 'reference' => '', 'kind' => $kind];

        return DocumentEvidence::create([
            'workspace_id' => $document->workspace_id,
            'document_id' => $document->id,
            'pipeline_key' => $document->ai_pipeline['key'] ?? 'pk-test',
            // Same 64-character hash identity the merge writes; the salt only keeps distinct
            // fixture findings from colliding on the (document, pipeline, identity) unique key.
            'identity' => hash('sha256', app(EvidenceMerger::class)->identity($record).bin2hex(random_bytes(4))),
            'kind' => $kind,
            'source_id' => $sourceId,
            'data' => $record,
            'sources' => [['chunk_id' => (string) Str::uuid(), 'span_id' => 'E001',
                'start_offset' => 10, 'end_offset' => 60, 'quote' => $record['quote'], 'page' => $page]],
        ]);
    }

    /** @param array<string,mixed> $attributes */
    protected function synthesis(Document $document, array $attributes = []): DocumentIntelligenceSummary
    {
        return DocumentIntelligenceSummary::create([
            'workspace_id' => $document->workspace_id,
            'document_id' => $document->id,
            'executive_summary' => 'The group grew its financing book while concentrating exposure in a few markets.',
            'key_findings' => ['Financing grew year on year.'],
            'critical_risks' => [], 'upcoming_deadlines' => [], 'important_entities' => [],
            'recommended_attention' => [],
            'prompt_version' => '3',
        ] + $attributes);
    }

    protected function riskFinding(Document $document, string $title, string $severity): DocumentRisk
    {
        $risk = DocumentRisk::create([
            'document_id' => $document->id, 'workspace_id' => $document->workspace_id,
            'title' => $title, 'description' => $title.' needs mitigation.', 'severity' => $severity,
            'confidence' => 0.88, 'status' => 'open', 'evidence' => $title.' was reported.',
            'prompt_version' => '3', 'provider' => 'anthropic',
        ]);
        $this->evidenceRow($document, 'risk', ['label' => $title, 'value' => $title.' needs mitigation.',
            'severity' => $severity], 'risk:'.$risk->id);

        return $risk;
    }
}
