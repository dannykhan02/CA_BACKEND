<?php

namespace Tests\Feature;

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
}
