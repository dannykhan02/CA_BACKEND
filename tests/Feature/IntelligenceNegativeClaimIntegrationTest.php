<?php

namespace Tests\Feature;

use App\Models\DocumentEvidence;
use App\Services\AnthropicClient;
use App\Services\Intelligence\DocumentAnalysisComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\BuildsIntelligenceFixtures;
use Tests\TestCase;

class IntelligenceNegativeClaimIntegrationTest extends TestCase
{
    use BuildsIntelligenceFixtures, RefreshDatabase;

    private function completeCoverage($document, array $overrides = []): void
    {
        $pipeline = $document->ai_pipeline;
        $pipeline['coverage'] = array_replace(['evidence_total' => 1, 'evidence_omitted' => 0,
            'unresolved_references' => 0, 'failed_chunks' => 0, 'total_chunks' => 1,
            'dropped_records' => 0, 'saturated_chunks' => 0, 'comprehensive' => true,
            'source_text' => 'full', 'synthesis_level' => 0], $overrides);
        $document->forceFill(['ai_pipeline' => $pipeline])->save();
    }

    public function test_v2_screens_ai_takeaways_before_selection_and_logs_only_metadata(): void
    {
        app()->bind(AnthropicClient::class, fn () => throw new \LogicException('Provider reached'));
        Http::fake();
        $document = $this->intelligenceDocument();
        $fact = $this->evidenceRow($document, 'fact', [
            'label' => 'Operating result', 'value' => 'Operating result improved.',
        ], 'fact:operating');
        $this->synthesis($document, [
            'material_findings' => [
                ['title' => 'No material risks were identified', 'source_ids' => [$fact->source_id]],
                ['title' => 'Operating result improved across the group', 'source_ids' => [$fact->source_id]],
            ],
            'key_findings' => ['No material risks were identified in the document.'],
        ]);
        Log::shouldReceive('info')->once()->with('docintel.v2.negative_claim_rejected',
            \Mockery::on(fn ($meta) => $meta === ['block_type' => 'synthesis',
                'reason' => 'negative_claim', 'count' => 1]));
        config(['intelligence_v2.enabled' => true]);
        $analysis = app(DocumentAnalysisComposer::class)->compose($document->fresh(),
            new \DateTimeImmutable('2026-10-07'));
        self::assertSame(['Operating result improved across the group.'],
            array_column($analysis['overview']['takeaways'], 'text'));
        self::assertSame(1, $analysis['stats']['briefAiBlocksRejected']);
        // key_findings is one of the five legacy summary arrays and remains unscreened.
        self::assertSame(['No material risks were identified in the document.'],
            array_column($analysis['overview']['summaryNotes'], 'text'));
        Http::assertNothingSent();
    }

    public function test_flag_off_keeps_the_legacy_takeaway_even_when_it_matches_the_v2_screen(): void
    {
        app()->bind(AnthropicClient::class, fn () => throw new \LogicException('Provider reached'));
        Http::fake();
        $document = $this->intelligenceDocument();
        $fact = $this->evidenceRow($document, 'fact', ['label' => 'Risk assessment',
            'value' => 'Risk assessment was recorded.'], 'fact:risk-assessment');
        $this->synthesis($document, ['material_findings' => [[
            'title' => 'No material risks were identified', 'source_ids' => [$fact->source_id],
        ]]]);
        config(['intelligence_v2.enabled' => false]);
        $analysis = app(DocumentAnalysisComposer::class)->compose($document->fresh(),
            new \DateTimeImmutable('2026-10-07'));
        self::assertSame(['No material risks were identified.'],
            array_column($analysis['overview']['takeaways'], 'text'));
        self::assertArrayNotHasKey('briefAiBlocksRejected', $analysis['stats']);
        Http::assertNothingSent();
    }

    public function test_v2_takeaway_caller_emits_only_the_guarded_deterministic_risk_absence(): void
    {
        app()->bind(AnthropicClient::class, fn () => throw new \LogicException('Provider reached'));
        Http::fake();
        $document = $this->intelligenceDocument();
        $this->completeCoverage($document);
        $risk = $this->riskFinding($document, 'Routine exposure', 'low');
        $this->synthesis($document, ['material_findings' => [[
            'title' => 'No material risks were identified', 'source_ids' => ['risk:'.$risk->id],
        ]]]);
        config(['intelligence_v2.enabled' => true]);
        $analysis = app(DocumentAnalysisComposer::class)->compose($document->fresh(),
            new \DateTimeImmutable('2026-10-07'));
        self::assertSame(1, $analysis['stats']['briefAiBlocksRejected']);
        self::assertCount(1, $analysis['overview']['takeaways']);
        $takeaway = $analysis['overview']['takeaways'][0];
        self::assertSame('No high or critical risks were identified.', $takeaway['text']);
        self::assertSame('absence.high_critical_risks', $takeaway['templateId']);
        self::assertSame('absent', $takeaway['assertion']);
        self::assertSame(['risk:'.$risk->id], $takeaway['sourceIds']);
        self::assertSame(['predicate' => 'risk_severity_in(high,critical)',
            'scope' => 'pipeline:pk-test', 'matched' => 0], $takeaway['absenceCheck']);
        Http::assertNothingSent();
    }

    public function test_v2_takeaway_caller_omits_absence_for_partial_unknown_or_matching_risk(): void
    {
        app()->bind(AnthropicClient::class, fn () => throw new \LogicException('Provider reached'));
        Http::fake();
        config(['intelligence_v2.enabled' => true]);
        foreach (['partial', 'unknown', 'matched'] as $case) {
            $document = $this->intelligenceDocument($case.'.pdf');
            $this->completeCoverage($document, $case === 'partial' ? ['failed_chunks' => 1] : []);
            $risk = $this->riskFinding($document, 'Exposure '.$case, $case === 'matched' ? 'high' : 'low');
            if ($case === 'unknown') {
                $row = DocumentEvidence::where('source_id', 'risk:'.$risk->id)->firstOrFail();
                $data = $row->data;
                $data['value'] = 'Unmatched paraphrase';
                $row->update(['data' => $data]);
            }
            $this->synthesis($document, ['material_findings' => [[
                'title' => 'No material risks were identified', 'source_ids' => ['risk:'.$risk->id],
            ]]]);
            $analysis = app(DocumentAnalysisComposer::class)->compose($document->fresh(),
                new \DateTimeImmutable('2026-10-07'));
            self::assertSame(1, $analysis['stats']['briefAiBlocksRejected'], $case);
            self::assertNotContains('absence.high_critical_risks',
                array_column($analysis['overview']['takeaways'], 'templateId'), $case);
        }
        Http::assertNothingSent();
    }
}
