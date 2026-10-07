<?php

namespace Tests\Feature;

use App\Services\AI\Incremental\EvidenceBudget;
use App\Services\AnthropicClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsIntelligenceFixtures;
use Tests\TestCase;

class IntelligenceMaterialityEvidenceBudgetTest extends TestCase
{
    use BuildsIntelligenceFixtures, RefreshDatabase;

    public function test_v2_consumes_scored_evidence_first_with_the_same_sixteen_thousand_byte_bound(): void
    {
        app()->bind(AnthropicClient::class, fn () => throw new \LogicException('Stage A reached a provider client'));
        self::assertSame(16000, config('document_intelligence.synthesis_token_budget'));
        $document = $this->intelligenceDocument();
        $padding = str_repeat('a', 4300);
        $metric = $this->evidenceRow($document, 'metric', [
            'label' => 'Uncited metric', 'value' => '10', 'unit' => 'USD',
            'quote' => 'Uncited metric 10 '.$padding,
        ], 'kpi:synthetic-metric');
        $fact = $this->evidenceRow($document, 'fact', [
            'label' => 'Cited finding', 'value' => 'Cited finding',
            'quote' => 'Cited finding '.$padding,
        ], 'fact:synthetic-fact');
        $this->synthesis($document, ['material_findings' => [[
            'title' => 'Cited finding', 'source_ids' => [$fact->source_id],
        ]]]);
        $budget = app(EvidenceBudget::class);
        config(['intelligence_v2.enabled' => false]);
        $v1 = $budget->forDocument($document->fresh());
        config(['intelligence_v2.enabled' => true]);
        $v2 = $budget->forDocument($document->fresh(), new \DateTimeImmutable('2026-10-07'));
        self::assertSame([$metric->source_id], array_column($v1['kpis'], 'id'));
        self::assertSame([$fact->source_id], array_column($v2['facts'], 'id'));
        self::assertSame(1, $v2['coverage']['evidence_omitted']);
        $bytes = 0;
        foreach (['entities', 'risks', 'deadlines', 'kpis', 'facts'] as $group) {
            foreach ($v2[$group] as $item) {
                $bytes += strlen(json_encode($item, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            }
        }
        self::assertLessThanOrEqual(16000, $bytes);
    }
}
