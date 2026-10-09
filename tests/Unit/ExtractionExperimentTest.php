<?php

namespace Tests\Unit;

use App\Models\DocumentEvidence;
use App\Services\AI\Incremental\EvidenceMerger;
use App\Services\AI\Incremental\EvidenceSchema;
use App\Services\AI\Incremental\ExtractionExperiment;
use App\Services\Intelligence\Values\TypedEvidenceProjector;
use Tests\TestCase;

class ExtractionExperimentTest extends TestCase
{
    public function test_independent_flags_and_control_version(): void
    {
        $control = EvidenceSchema::instructions('spans');
        self::assertSame('2', (new ExtractionExperiment)->promptVersion('2'));
        self::assertSame($control, (new ExtractionExperiment)->instructions($control));

        foreach ([[true, false, 'diagnostic-collector-v1'], [false, true, 'diagnostic-metadata-v1'],
            [true, true, 'diagnostic-collector-metadata-v1']] as [$collector, $metadata, $version]) {
            $option = new ExtractionExperiment($collector, $metadata);
            $prompt = $option->instructions($control);
            self::assertSame($version, $option->promptVersion('2'));
            self::assertSame($collector, str_contains($prompt, 'without ranking them'));
            self::assertSame($metadata, str_contains($prompt, 'OPTIONAL METADATA DISCIPLINE'));
            self::assertSame($metadata ? 1 : 0, substr_count($prompt, 'OPTIONAL METADATA DISCIPLINE'));
            if ($collector) {
                self::assertStringNotContainsString('keep the most material ones', $prompt);
                self::assertStringContainsString('coverage may be incomplete', $prompt);
            }
        }
        self::assertSame('2', config('document_intelligence.prompt_version'));
    }

    public function test_null_period_can_merge_distinct_years(): void
    {
        $merger = app(EvidenceMerger::class);
        $projector = app(TypedEvidenceProjector::class);
        $identities = [];
        foreach (['2023', '2024'] as $year) {
            $record = ['kind' => 'metric', 'label' => 'Revenue', 'subject' => 'Company',
                'period' => $year, 'unit' => 'USD million', 'value' => '$50 million',
                'metric_type' => 'actual', 'value_basis' => 'total', 'quote' => "$year revenue was $50 million."];
            $identities[$year] = $merger->identity($record);
            $row = new DocumentEvidence;
            $row->forceFill(['kind' => 'metric', 'data' => $record,
                'sources' => [['quote' => $record['quote']]]]);
            $projected = $projector->project($row, []);
            self::assertSame('document', $projected['provenance']['origin']);
            self::assertArrayHasKey('period_covered', $projected['typed']['dates']);
            $record['period'] = null;
            $identities['null_'.$year] = $merger->identity($record);
            $row->forceFill(['data' => $record]);
            $projected = $projector->project($row, []);
            self::assertSame('document', $projected['provenance']['origin']);
            self::assertArrayNotHasKey('period_covered', $projected['typed']['dates']);
        }
        self::assertNotSame($identities['2023'], $identities['2024']);
        self::assertSame($identities['null_2023'], $identities['null_2024']);
    }
}
