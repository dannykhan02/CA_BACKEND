<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Models\DocumentKpi;
use App\Models\User;
use App\Services\DocumentTextExtractor;
use App\Services\Intelligence\ChartCandidateBuilder;
use App\Services\Intelligence\MetricCollector;
use App\Services\Intelligence\NormalKpiGrounder;
use App\Services\Intelligence\SourceReferenceResolver;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NorthstarProductionBaselineTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_twelve_v7_candidates_remain_unverified_without_persisted_evidence(): void
    {
        $fixture = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/intelligence-v2/'
            .'northstar-production-v7-kpis.json'), true, flags: JSON_THROW_ON_ERROR);
        $path = dirname(__DIR__).'/Fixtures/Northstar_Q3_2026_Board_Performance_Review.pdf';
        $text = (new DocumentTextExtractor)->extractPdfText($path);
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $document = Document::create(['workspace_id' => $user->fresh()->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => basename($path), 'type' => 'PDF',
            'status' => 'Ready', 'classification' => 'Internal', 'size_kb' => 81,
            'year' => 2026, 'pages' => 7, 'file_hash' => hash_file('sha256', $path),
            'extracted_text' => $text]);
        $document->forceFill(['ai_pipeline' => ['route' => 'normal', 'key' => 'normal:v7-baseline']])->save();
        foreach ($fixture['kpis'] as $row) {
            DocumentKpi::create(['document_id' => $document->id, 'workspace_id' => $document->workspace_id,
                'label' => $row['label'], 'value' => $row['value'], 'unit' => $row['unit'],
                'period' => $row['period'], 'identity_metadata' => [
                    'scope' => $row['scope'], 'concept' => $row['concept'],
                    'metric_type' => $row['metric_type'], 'value_basis' => $row['value_basis'],
                    'period' => $row['identity_period'] ?? $row['period'],
                    'aggregation' => 'total', 'quantity_kind' => 'absolute']]);
        }
        $result = app(MetricCollector::class)->collect($document->fresh());
        self::assertSame(12, $result['stats']['metric_findings']);
        self::assertCount(12, $result['observations']);
        self::assertSame(0, $result['stats']['chartable']);
        foreach ($result['observations'] as $observation) {
            self::assertFalse($observation->integrity['analyticalEligible']);
            self::assertContains('missing_source_reference', $observation->integrity['reasons']);
        }
        self::assertSame([], app(ChartCandidateBuilder::class)->build($result['observations'])['candidates']);
    }

    public function test_v8_style_pdf_table_claims_bind_to_the_correct_financial_and_delivery_cells(): void
    {
        $path = dirname(__DIR__).'/Fixtures/Northstar_Q3_2026_Board_Performance_Review.pdf';
        $text = (new DocumentTextExtractor)->extractPdfText($path);
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $document = Document::create(['workspace_id' => $user->fresh()->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => basename($path), 'type' => 'PDF',
            'status' => 'Processing', 'classification' => 'Internal', 'size_kb' => 81,
            'year' => 2026, 'pages' => 7, 'file_hash' => hash_file('sha256', $path),
            'extracted_text' => $text]);
        $document->forceFill(['ai_pipeline' => ['route' => 'normal', 'key' => 'normal:northstar-v8',
            'normal_span_grounding' => true]])->save();
        $claims = [
            ['Operating profit', '10.0', 'KES million', 'Q3 2026', 'quarterly operating profit',
                'actual', 'E031', 'E027', 'E028', 'E025', null],
            ['Revenue', '51.0', 'KES million', 'Q3 2026', 'quarterly revenue',
                'actual', 'E029', 'E027', 'E028', 'E025', null],
            ['Operating costs', '41.0', 'KES million', 'Q3 2026', 'quarterly operating costs',
                'actual', 'E030', 'E027', 'E028', 'E025', null],
            ['Operating margin', '19.6', '%', 'Q3 2026', 'quarterly operating margin',
                'actual', 'E032', 'E032', 'E028', 'E025', null],
            ['Operating profit', '9.0', 'KES million', 'Q3 2026', 'quarterly operating profit',
                'actual', 'E031', 'E027', 'E028', 'E025', 'value_column_mismatch'],
            ['Operating profit', '10.0', 'KES million', 'Q1 2026', 'quarterly operating profit',
                'actual', 'E031', 'E027', 'E028', 'E025', 'value_column_mismatch'],
            ['Revenue', '51.0', 'USD million', 'Q3 2026', 'quarterly revenue',
                'actual', 'E029', 'E027', 'E028', 'E025', 'unit_source_mismatch'],
            ['On-time deliveries', '87', '%', 'Q3', 'on-time delivery rate',
                'actual', 'E048', 'E048', 'E046', 'E043', null],
            ['On-time deliveries', '95', '%', 'Q3', 'on-time delivery rate',
                'target', 'E048', 'E048', 'E046', 'E043', null],
        ];
        foreach ($claims as [$label, $value, $unit, $period, $concept, $basis,
            $valueSpan, $unitSpan, $periodSpan, $subjectSpan, $expectedRejection]) {
            $kpi = DocumentKpi::create(['document_id' => $document->id,
                'workspace_id' => $document->workspace_id, 'label' => $label,
                'value' => $value, 'unit' => $unit, 'period' => $period,
                'identity_metadata' => ['concept' => $concept, 'scope' => 'Northstar Distribution Ltd',
                    'metric_type' => $unit === '%' ? 'percentage' : 'amount', 'value_basis' => $basis]]);
            $actual = app(NormalKpiGrounder::class)->record($document, $kpi,
                ['source_context' => ['value_span_id' => $valueSpan, 'unit_span_id' => $unitSpan,
                    'period_span_id' => $periodSpan, 'subject_span_id' => $subjectSpan]]);
            self::assertSame($expectedRejection, $actual, $label.' '.$value.' '.$basis);
            if ($actual === null) {
                $evidence = DocumentEvidence::where('source_id', 'kpi:'.$kpi->id)->sole();
                $start = $evidence->data['source_cell_start_offset'];
                $end = $evidence->data['source_cell_end_offset'];
                self::assertSame($evidence->data['source_cell_value'],
                    mb_substr($text, $start, $end - $start));
                $observations = collect(app(MetricCollector::class)->collect($document)['observations'])
                    ->keyBy('sourceId');
                self::assertTrue($observations['kpi:'.$kpi->id]->integrity['analyticalEligible']);
            }
        }
    }

    public function test_the_original_pdf_financial_table_can_drive_verified_reproducible_series(): void
    {
        $path = dirname(__DIR__).'/Fixtures/Northstar_Q3_2026_Board_Performance_Review.pdf';
        $text = (new DocumentTextExtractor)->extractPdfText($path);
        $gold = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/intelligence-v2/'
            .'northstar-source-gold.json'), true, flags: JSON_THROW_ON_ERROR)['cases'][0]['gold'];
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $document = Document::create(['workspace_id' => $user->fresh()->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => basename($path), 'type' => 'PDF',
            'status' => 'Processing', 'classification' => 'Internal', 'size_kb' => 81,
            'year' => 2026, 'pages' => 7, 'file_hash' => hash_file('sha256', $path),
            'extracted_text' => $text]);
        $document->forceFill(['ai_pipeline' => ['route' => 'normal', 'key' => 'normal:northstar-financial',
            'normal_span_grounding' => true]])->save();
        $spans = ['Revenue' => 'E029', 'Operating costs' => 'E030',
            'Operating profit' => 'E031', 'Operating margin' => 'E032'];
        $profitSourceId = null;
        foreach (array_slice($gold, 0, 12) as $item) {
            $isPercent = $item['unit'] === '%';
            $value = $isPercent ? rtrim($item['value'], '%') : $item['value'];
            $kpi = DocumentKpi::create(['document_id' => $document->id,
                'workspace_id' => $document->workspace_id, 'label' => $item['metric'],
                'value' => $value, 'unit' => $item['unit'], 'period' => $item['period'],
                'identity_metadata' => ['concept' => 'quarterly '.mb_strtolower($item['metric']),
                    'scope' => 'Northstar Distribution Ltd', 'metric_type' => $isPercent ? 'percentage' : 'amount',
                    'value_basis' => 'actual']]);
            self::assertNull(app(NormalKpiGrounder::class)->record($document, $kpi,
                ['source_context' => ['value_span_id' => $spans[$item['metric']],
                    'unit_span_id' => $isPercent ? $spans[$item['metric']] : 'E027',
                    'period_span_id' => 'E028', 'subject_span_id' => 'E025']]), $item['id']);
            if ($item['id'] === 'profit-q3') {
                $profitSourceId = 'kpi:'.$kpi->id;
            }
        }
        $document->forceFill(['status' => 'Ready'])->save();
        $resolved = app(SourceReferenceResolver::class)->resolve($document->fresh(), $profitSourceId);
        self::assertSame('CURRENT', $resolved['state']);
        self::assertSame(3, $resolved['source']['page']);
        self::assertSame('10.0', $resolved['source']['text']);
        self::assertSame('repeated_occurrence', $resolved['quality']['ambiguity']);
        self::assertCount(4, $resolved['fieldSources']);
        $rowStart = mb_strpos($text, "Operating profit\t9.0\t10.0\t10.0");
        $q3Cell = $rowStart + mb_strlen("Operating profit\t9.0\t10.0\t");
        self::assertSame($q3Cell, $resolved['source']['range']['start']);
        $collected = app(MetricCollector::class)->collect($document);
        self::assertSame(12, $collected['stats']['chartable']);
        $charts = app(ChartCandidateBuilder::class)->build($collected['observations'])['candidates'];
        $series = array_values(array_filter($charts, static fn (array $chart): bool => $chart['basis'] === 'time_series'));
        self::assertCount(4, $series);
        foreach ($series as $chart) {
            self::assertCount(3, $chart['points']);
            self::assertCount(3, $chart['sourceIds']);
            self::assertSame(['Q1 2026', 'Q2 2026', 'Q3 2026'], array_column($chart['points'], 'label'));
        }
        $byMetric = collect($series)->keyBy('metric');
        self::assertSame([42.0, 46.0, 51.0], array_column($byMetric['Revenue']['points'], 'value'));
        self::assertSame([33.0, 36.0, 41.0], array_column($byMetric['Operating costs']['points'], 'value'));
        self::assertSame([9.0, 10.0, 10.0], array_column($byMetric['Operating profit']['points'], 'value'));
        self::assertSame([21.4, 21.7, 19.6], array_column($byMetric['Operating margin']['points'], 'value'));
        self::assertSame('KES million', $byMetric['Revenue']['unit']);
        self::assertSame('%', $byMetric['Operating margin']['unit']);
        foreach ($series as $chart) {
            self::assertSame('verified', $chart['sourceVerification']);
            self::assertSame('verified', $chart['unitVerification']);
            foreach ($chart['points'] as $point) {
                self::assertCount(1, $point['sourceIds']);
                self::assertSame(3, $point['page']);
                self::assertSame($chart['sourceUnit'], $point['sourceUnit']);
            }
        }
        $document->forceFill(['extracted_text' => $text."\n"])->save();
        self::assertSame('SOURCE_UNAVAILABLE', app(SourceReferenceResolver::class)
            ->resolve($document->fresh(), $profitSourceId)['state']);
    }
}
