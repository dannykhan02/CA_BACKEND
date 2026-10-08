<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Models\DocumentSourceSpan;
use App\Services\Intelligence\Materiality\MaterialityReadModel;
use App\Services\Intelligence\ProvenanceDiagnostic;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Observes accepted rows only. PostgreSQL rejects writes inside the read-only transaction. */
class ProvenanceDiagnosticReport extends Command
{
    protected $signature = 'docintel:provenance-diagnostic {document} {--json}';

    protected $description = 'Read-only diagnosis of unknown-origin V2 metric evidence';

    private const BUCKETS = ['numeric_equivalent', 'surrounding_context_supported',
        'extraction_wording_mismatch', 'genuinely_unsupported', 'ambiguous'];

    private const REASONS = ['value_not_in_quote', 'period_not_in_quote', 'due_date_not_grounded', 'other'];

    public function handle(MaterialityReadModel $readModel, ProvenanceDiagnostic $diagnostic): int
    {
        $report = DB::transaction(function () use ($readModel, $diagnostic): ?array {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION READ ONLY');
            }

            $document = Document::query()->whereKey($this->argument('document'))->first();
            if (! $document) {
                return null;
            }
            $key = $document->ai_pipeline['key'] ?? null;
            $evidence = is_string($key) ? DocumentEvidence::query()
                ->where('workspace_id', $document->workspace_id)->where('document_id', $document->id)
                ->where('pipeline_key', $key)->orderBy('identity')->get() : collect();
            $records = $readModel->build($document, $evidence, [], [])['records'];
            $version = $document->ai_pipeline['extraction_version'] ?? null;
            $spans = is_string($version) ? DocumentSourceSpan::query()
                ->where('workspace_id', $document->workspace_id)->where('document_id', $document->id)
                ->where('extraction_version', $version)->orderBy('ordinal')->get()->keyBy('span_key')->all() : [];

            $metrics = array_values(array_filter($records, fn ($r) => ($r['kind'] ?? null) === 'metric'));
            $unknown = array_values(array_filter($metrics,
                fn ($r) => ($r['provenance']['origin'] ?? null) === 'unknown'));
            $documentOrigin = count($metrics) - count($unknown);
            $count = count($metrics);
            $buckets = array_fill_keys(self::BUCKETS, ['count' => 0, 'samples' => []]);
            $reasons = array_fill_keys(self::REASONS, 0);
            foreach ($unknown as $record) {
                $result = $diagnostic->classify($record, $spans, (string) $document->extracted_text);
                $bucket = $result['diagnostic_bucket'];
                $reason = $result['original_reason'];
                $buckets[$bucket]['count']++;
                $reasons[$reason]++;
                if (count($buckets[$bucket]['samples']) < 20) {
                    $buckets[$bucket]['samples'][] = $result;
                }
            }

            return [
                'document' => ['id' => $document->id, 'name' => $document->name],
                'summary' => ['total_evidence' => count($records), 'total_metrics' => $count,
                    'document_origin_metrics' => $documentOrigin, 'unknown_origin_metrics' => count($unknown),
                    'document_origin_percentage' => $count ? round(100 * $documentOrigin / $count, 2) : 0,
                    'unknown_origin_percentage' => $count ? round(100 * count($unknown) / $count, 2) : 0,
                    'key_figure_eligible_count' => count(array_filter($metrics, static function (array $record): bool {
                        $value = $record['typed']['value'] ?? null;

                        return ($record['provenance']['origin'] ?? null) === 'document'
                            && ($value['type'] ?? null) === 'money'
                            && ($value['unit_kind'] ?? null) === 'currency'
                            && is_string($value['currency'] ?? null) && $value['currency'] !== ''
                            && is_numeric($value['number'] ?? null) && is_finite((float) $value['number'])
                            && is_string($record['source_id'] ?? null);
                    })),
                ],
                'unknown_reasons' => $reasons,
                'diagnostic_classifications' => $buckets,
            ];
        });

        if ($report === null) {
            $this->error('Document not found.');

            return self::FAILURE;
        }
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

            return self::SUCCESS;
        }

        $this->line($report['document']['name'].' ('.$report['document']['id'].')');
        foreach ($report['summary'] as $name => $value) {
            $this->line($name.': '.$value);
        }
        $this->line('Unknown reasons:');
        foreach ($report['unknown_reasons'] as $name => $value) {
            $this->line('  '.$name.': '.$value);
        }
        foreach ($report['diagnostic_classifications'] as $name => $bucket) {
            $this->line($name.': '.$bucket['count']);
            foreach ($bucket['samples'] as $sample) {
                $this->line('  '.json_encode($sample, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
            }
        }

        return self::SUCCESS;
    }
}
