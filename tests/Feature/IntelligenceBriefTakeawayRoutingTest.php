<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Services\AnthropicClient;
use App\Services\Intelligence\DocumentAnalysisComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsIntelligenceFixtures;
use Tests\TestCase;

class IntelligenceBriefTakeawayRoutingTest extends TestCase
{
    use BuildsIntelligenceFixtures, RefreshDatabase;

    private function candidateDocument(): Document
    {
        $document = $this->intelligenceDocument();
        $kpi = $this->metricFinding($document, 'Revenue', '11', 'USD billion', 'FY2025',
            ['quote' => 'Revenue was 11 in FY2025.']);
        $this->synthesis($document, ['material_findings' => [[
            'title' => 'Revenue was USD 11 billion in FY2025',
            'why_it_matters' => 'The reported measure matters to the review.',
            'basis' => 'explicit', 'source_ids' => ['kpi:'.$kpi->id],
        ]]]);

        return $document;
    }

    public function test_document_origin_typed_synthesis_candidate_passes_and_unknown_origin_is_omitted(): void
    {
        config(['intelligence_v2.enabled' => true]);
        app()->bind(AnthropicClient::class, fn () => throw new \LogicException('B1 reached a provider client'));
        $document = $this->candidateDocument();
        $composer = app(DocumentAnalysisComposer::class);
        $asOf = new \DateTimeImmutable('2026-10-07T00:00:00+00:00');
        $pass = $composer->compose($document->fresh(), $asOf);
        $synthesis = collect($pass['overview']['takeaways'])->firstWhere('origin', 'synthesis');
        self::assertNotNull($synthesis);
        self::assertTrue($synthesis['ai_generated']);
        self::assertSame('passed', $synthesis['verification']['status']);
        self::assertSame(0, $pass['stats']['briefAiBlocksRejected']);

        $evidence = DocumentEvidence::where('workspace_id', $document->workspace_id)
            ->where('document_id', $document->id)->firstOrFail();
        $data = $evidence->data;
        $data['quote'] = 'Revenue was 11.';
        $evidence->data = $data;
        $sources = $evidence->sources;
        $sources[0]['quote'] = 'Revenue was 11.';
        $evidence->sources = $sources;
        $evidence->save();
        $unknown = $composer->compose($document->fresh(), $asOf);
        self::assertNull(collect($unknown['overview']['takeaways'])->firstWhere('origin', 'synthesis'));
        self::assertSame(1, $unknown['stats']['briefAiBlocksRejected']);
    }

    public function test_flag_off_preserves_the_existing_unverified_synthesis_takeaway_shape(): void
    {
        config(['intelligence_v2.enabled' => false]);
        $document = $this->candidateDocument();
        $analysis = app(DocumentAnalysisComposer::class)->compose($document->fresh(),
            new \DateTimeImmutable('2026-10-07T00:00:00+00:00'));
        $synthesis = collect($analysis['overview']['takeaways'])->firstWhere('origin', 'synthesis');
        self::assertNotNull($synthesis);
        self::assertArrayNotHasKey('ai_generated', $synthesis);
        self::assertArrayNotHasKey('verification', $synthesis);
        self::assertArrayNotHasKey('briefAiBlocksRejected', $analysis['stats']);
    }
}
