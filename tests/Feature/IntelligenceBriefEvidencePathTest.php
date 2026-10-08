<?php

namespace Tests\Feature;

use App\Services\Intelligence\Brief\BriefAssembler;
use App\Services\Intelligence\Materiality\MaterialityReadModel;
use App\Services\Intelligence\Materiality\MaterialityScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsIntelligenceFixtures;
use Tests\TestCase;

class IntelligenceBriefEvidencePathTest extends TestCase
{
    use BuildsIntelligenceFixtures, RefreshDatabase;

    public function test_stored_record_id_survives_read_model_and_brief_evidence(): void
    {
        $document = $this->intelligenceDocument();
        $stored = $this->evidenceRow($document, 'metric', [
            'label' => 'Total financing', 'value' => 'USD 12 billion',
            'quote' => 'Total financing: USD 12 billion.',
        ], 'kpi:stored-figure');
        $read = app(MaterialityReadModel::class)->build($document, collect([$stored]), [], []);
        $record = $read['records'][0];
        self::assertSame($stored->id, $record['record_id']);
        self::assertSame('kpi:stored-figure', $record['source_id']);
        $asOf = new \DateTimeImmutable('2026-10-08');
        $assignments = app(MaterialityScorer::class)->assign($read['records'], $read['context'], $asOf);
        $brief = app(BriefAssembler::class)->assemble($document->name, $document->type,
            $read['records'], $assignments, ['state' => 'complete', 'reasons' => []], $asOf);
        $measure = collect($brief['blocks'])->firstWhere('type', 'measure');
        self::assertNotNull($measure);
        self::assertNotEmpty($measure['evidence']);
        self::assertSame($stored->id, $measure['evidence'][0]['record_id']);
        self::assertSame($stored->id, \App\Models\DocumentEvidence::findOrFail($measure['evidence'][0]['record_id'])->id);
        self::assertSame('kpi:stored-figure', $measure['evidence'][0]['source_id']);
        self::assertSame(10, $measure['evidence'][0]['start_offset']);
        self::assertSame(60, $measure['evidence'][0]['end_offset']);

        unset($record['record_id']);
        $withoutId = app(BriefAssembler::class)->assemble($document->name, $document->type,
            [$record], $assignments, ['state' => 'complete', 'reasons' => []], $asOf);
        self::assertSame([], collect($withoutId['blocks'])->firstWhere('type', 'measure')['evidence']);
    }
}
