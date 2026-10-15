<?php

namespace Tests\Feature;

use App\Exceptions\AiProcessingException;
use App\Exceptions\AnthropicStructuredOutputException;
use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\DocumentChunk;
use App\Services\AI\AiPricing;
use App\Services\AI\Incremental\EvidenceBudget;
use App\Services\AI\Incremental\IncrementalPipeline;
use App\Services\AI\Incremental\SynthesisBatches;
use App\Services\AI\Incremental\SynthesisCoverage;
use App\Services\AnthropicClient;
use Database\Seeders\DocumentSummaryPromptSeederV3;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Tests\Concerns\BuildsIntelligenceFixtures;
use Tests\TestCase;

class LargeSynthesisCoverageTest extends TestCase
{
    use BuildsIntelligenceFixtures, RefreshDatabase;

    private function cbkStyleDocument(): Document
    {
        config(['intelligence_v2.enabled' => false]);
        $document = $this->intelligenceDocument('CBK style 2025 Annual Report.pdf', 'Processing');
        $document->forceFill(['ai_pipeline' => ['key' => 'pk-test', 'route' => 'incremental',
            'budget_usd' => 2.0, 'synthesis' => 'pending', 'tokens' => 60000]])->save();
        $kinds = ['metric' => 546, 'definition' => 110, 'fact' => 110, 'entity' => 23,
            'deadline' => 6, 'obligation' => 3, 'risk' => 1];
        $rows = [];
        $ordinal = 0;
        foreach ($kinds as $kind => $count) {
            for ($i = 0; $i < $count; $i++) {
                $id = $kind === 'metric' ? 'kpi' : (in_array($kind, ['definition', 'fact'], true) ? 'fact'
                    : ($kind === 'obligation' ? 'deadline' : $kind));
                $sourceId = $id.':fixture-'.str_pad((string) $ordinal, 4, '0', STR_PAD_LEFT);
                $conflict = $kind === 'metric' && in_array($i, [100, 101], true);
                $label = $conflict ? 'Capital ratio' : ucfirst($kind).' finding '.$ordinal;
                $value = $kind === 'risk' ? 'Critical liquidity concentration'
                    : ($conflict ? ($i === 100 ? '10' : '12') : (string) (1000 + $ordinal));
                $quote = $label.' '.$value.' '.str_repeat('context ', 67);
                $page = 1 + $ordinal % 120;
                $rows[] = ['id' => (string) Str::uuid(), 'workspace_id' => $document->workspace_id,
                    'document_id' => $document->id, 'pipeline_key' => 'pk-test',
                    'identity' => hash('sha256', $sourceId), 'kind' => $kind, 'source_id' => $sourceId,
                    'data' => json_encode(['kind' => $kind, 'label' => $label, 'value' => $value,
                        'quote' => $quote, 'unit' => $kind === 'metric' ? 'KES million' : null,
                        'period' => '2025', 'comparison_period' => '2024',
                        'source_conflict' => $conflict ? 'conflicting' : null,
                        'severity' => $kind === 'risk' ? 'critical' : null]),
                    'sources' => json_encode([['page' => $page, 'start_offset' => $ordinal * 200,
                        'end_offset' => $ordinal * 200 + 100, 'span_id' => 'E'.str_pad((string) $ordinal, 4, '0', STR_PAD_LEFT),
                        'quote' => $quote]]), 'created_at' => now(), 'updated_at' => now()];
                $ordinal++;
            }
        }
        foreach (array_chunk($rows, 100) as $slice) {
            DB::table('document_evidence')->insert($slice);
        }

        return $document->fresh();
    }

    public function test_799_record_fixture_reproduces_legacy_loss_and_accounts_for_every_record_after_batching(): void
    {
        $this->seed(DocumentSummaryPromptSeederV3::class);
        $document = $this->cbkStyleDocument();
        $legacy = app(EvidenceBudget::class)->forDocument($document);
        $legacyIncluded = 799 - $legacy['coverage']['evidence_omitted'];
        self::assertSame(799, $legacy['coverage']['evidence_total']);
        self::assertSame(11, $legacyIncluded);

        $plan = app(SynthesisCoverage::class)->plan($document);
        self::assertSame(799, $plan['eligible']);
        self::assertSame([], $plan['uncovered']);
        self::assertGreaterThan(1, count($plan['batches']));
        self::assertStringEndsWith(':risk', $plan['batches'][0]['group']);
        self::assertSame(23, count($plan['batches']));
        self::assertLessThanOrEqual((int) config('document_intelligence.synthesis_max_batches'), count($plan['batches']));
        self::assertSame(799, array_sum(array_map(fn ($batch) => count($batch['items']), $plan['batches'])));
        $conflicts = collect($plan['batches'])->flatMap(fn ($batch) => $batch['items'])
            ->filter(fn ($item) => ($item['label'] ?? '') === 'Capital ratio')->pluck('value')->all();
        self::assertEqualsCanonicalizing(['10', '12'], $conflicts);
        $pricingClient = app(AnthropicClient::class);
        $batchCeiling = array_sum(array_map(fn ($batch) => $pricingClient->evidenceBatchReservation($batch), $plan['batches']));
        self::assertLessThan(0.95, $batchCeiling);
        foreach ($plan['batches'] as $batch) {
            self::assertLessThanOrEqual(16000, strlen(json_encode($batch, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
        }

        $client = Mockery::mock(AnthropicClient::class);
        $client->shouldReceive('evidenceBatchReservation')->andReturn(0.025);
        $client->shouldReceive('synthesisReservation')->andReturn(['synthesis_reserved_usd' => 0.2,
            'repair_reserved_usd' => 0.05]);
        $client->shouldReceive('setRunContext')->zeroOrMoreTimes();
        $client->shouldReceive('summarizeEvidenceBatch')->andReturnUsing(function ($doc, $batch) {
            $first = $batch['items'][0];
            $last = $batch['items'][count($batch['items']) - 1];

            return ['findings' => [['id' => $first['id'], 'claim' => $first['claim']],
                ['id' => $last['id'], 'claim' => $last['claim']]], 'invalid_claims' => 0];
        });
        $processor = app(SynthesisBatches::class);
        foreach ($plan['batches'] as $batch) {
            self::assertSame('processed', $processor->processNext($document, null, $client));
            $unit = DocumentChunk::where('identity', $processor->identity($batch))->sole();
            self::assertSame('completed', $unit->status);
            self::assertTrue($unit->cost_accounting['settled']);
        }
        self::assertSame('ready', $processor->processNext($document, null, $client));
        $data = app(EvidenceBudget::class)->forSynthesis($document->fresh());
        self::assertSame(799, $data['coverage']['synthesis_considered']);
        self::assertSame(0, $data['coverage']['synthesis_uncovered']);
        self::assertSame([], $data['coverage']['uncovered_groups']);
        self::assertGreaterThan(0, $data['coverage']['presentation_omitted']);
        self::assertSame(799, $data['coverage']['presentation_omitted'] + $data['coverage']['presentation_selected']);
        self::assertTrue($data['coverage']['comprehensive']);
        $selectedIds = collect(['entities', 'risks', 'deadlines', 'kpis', 'facts'])
            ->flatMap(fn ($group) => array_column($data[$group], 'id'))->all();
        self::assertContains('risk:fixture-0798', $selectedIds);
        self::assertContains('kpi:fixture-0000', $selectedIds);
        self::assertContains('kpi:fixture-0399', $selectedIds);
        self::assertLessThanOrEqual(2.0, (float) DocumentChunk::where('document_id', $document->id)->sum('reserved_cost'));
        $document->forceFill(['ai_pipeline' => [...$document->ai_pipeline, 'synthesis_reductions' => 3]])->save();
        $final = $pricingClient->synthesisReservation($document->fresh(), app(EvidenceBudget::class)->forSynthesis($document->fresh()), 3);
        // The production example had $0.863037 of actual recorded spend before this work.
        self::assertLessThan(2.0, 0.863037 + $batchCeiling + $final['synthesis_reserved_usd'] + $final['repair_reserved_usd']);
        config(['document_intelligence.hierarchical_synthesis' => false]);
        self::assertSame(788, app(EvidenceBudget::class)->forSynthesis($document->fresh())['coverage']['evidence_omitted']);
    }

    public function test_unaffordable_batches_are_reported_with_exact_groups_and_counts(): void
    {
        $document = $this->cbkStyleDocument();
        $document->forceFill(['ai_pipeline' => [...$document->ai_pipeline, 'budget_usd' => 0.3]])->save();
        $client = Mockery::mock(AnthropicClient::class);
        $client->shouldReceive('evidenceBatchReservation')->andReturn(0.1);
        $client->shouldReceive('synthesisReservation')->andReturn(['synthesis_reserved_usd' => 0.2,
            'repair_reserved_usd' => 0.05]);
        $processor = app(SynthesisBatches::class);
        self::assertSame('ready', $processor->processNext($document, null, $client));
        $coverage = app(EvidenceBudget::class)->forSynthesis($document)['coverage'];
        self::assertSame(798, $coverage['synthesis_uncovered']);
        self::assertSame(1, $coverage['synthesis_considered']);
        self::assertNotEmpty($coverage['uncovered_groups']);
        self::assertSame(798, array_sum(array_column($coverage['uncovered_groups'], 'count')));
        self::assertFalse($coverage['comprehensive']);
    }

    public function test_timed_out_and_truncated_intermediates_are_explicitly_uncovered(): void
    {
        $document = $this->cbkStyleDocument();
        $plan = app(SynthesisCoverage::class)->plan($document);
        $client = Mockery::mock(AnthropicClient::class);
        $client->shouldReceive('evidenceBatchReservation')->andReturn(0.025);
        $client->shouldReceive('synthesisReservation')->andReturn(['synthesis_reserved_usd' => 0.2,
            'repair_reserved_usd' => 0.05]);
        $client->shouldReceive('setRunContext')->zeroOrMoreTimes();
        $calls = 0;
        $client->shouldReceive('summarizeEvidenceBatch')->andReturnUsing(function ($doc, $batch) use (&$calls) {
            $calls++;
            if ($calls <= 2) {
                throw new AiProcessingException($calls === 1 ? 'timeout' : 'truncated');
            }

            return ['findings' => [['id' => $batch['items'][0]['id'], 'claim' => $batch['items'][0]['claim']]]];
        });
        $processor = app(SynthesisBatches::class);
        foreach ($plan['batches'] as $batch) {
            self::assertSame('processed', $processor->processNext($document, null, $client));
        }
        self::assertSame('ready', $processor->processNext($document, null, $client));
        $coverage = app(EvidenceBudget::class)->forSynthesis($document)['coverage'];
        $missing = count($plan['batches'][0]['items']) + count($plan['batches'][1]['items']);
        self::assertSame($missing, $coverage['synthesis_uncovered']);
        self::assertSame(799 - $missing, $coverage['synthesis_considered']);
        self::assertEqualsCanonicalizing(['timeout', 'truncated'],
            array_values(array_unique(array_column($coverage['uncovered_groups'], 'reason'))));
        self::assertSame(23, $calls);
    }

    public function test_batch_provider_rejects_invented_ids_and_modified_claims(): void
    {
        config(['services.anthropic.synthesis_model' => 'claude-sonnet-5-5']);
        $document = $this->intelligenceDocument('Small source.pdf', 'Processing');
        $document->forceFill(['ai_pipeline' => [...$document->ai_pipeline, 'budget_usd' => 2.0]])->save();
        $batch = ['group' => 'page:0:metric', 'items' => [
            ['id' => 'kpi:valid', 'type' => 'metric', 'claim' => 'Revenue: KES 10 million', 'citation' => [['page' => 1]]],
        ]];
        Http::fake(['*/messages' => Http::response(['model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 200, 'output_tokens' => 100],
            'content' => [['type' => 'text', 'text' => json_encode(['findings' => [
                ['id' => 'kpi:valid', 'claim' => 'Revenue: KES 10 million'],
                ['id' => 'kpi:valid', 'claim' => 'Revenue: KES 100 million'],
                ['id' => 'kpi:invented', 'claim' => 'Invented claim'],
            ]])]]])]);
        $result = app(AnthropicClient::class)->summarizeEvidenceBatch($document, $batch);
        self::assertSame([['id' => 'kpi:valid', 'claim' => 'Revenue: KES 10 million']], $result['findings']);
        self::assertSame(2, $result['invalid_claims']);
        $estimate = app(AnthropicClient::class)->evidenceBatchReservation($batch);
        self::assertGreaterThan(0, $estimate);
        self::assertLessThan(2, $estimate);
    }

    public function test_batch_parser_rejects_missing_findings_without_marking_the_call_successful(): void
    {
        config(['services.anthropic.synthesis_model' => 'claude-sonnet-5-5']);
        $document = $this->intelligenceDocument('Malformed batch.pdf', 'Processing');
        $document->forceFill(['ai_pipeline' => [...$document->ai_pipeline, 'budget_usd' => 2.0]])->save();
        Http::fake(['*/messages' => Http::response(['model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 200, 'output_tokens' => 20],
            'content' => [['type' => 'text', 'text' => '{}']]])]);
        try {
            app(AnthropicClient::class)->summarizeEvidenceBatch($document,
                ['group' => 'page:0:metric', 'items' => [['id' => 'kpi:valid', 'claim' => 'Revenue: 10']]]);
            self::fail('A missing findings array must fail validation.');
        } catch (AnthropicStructuredOutputException $e) {
            self::assertSame('invalid_schema', $e->outputStatus);
        }
        self::assertSame('invalid_schema', DocumentAiRun::where('document_id', $document->id)->sole()->failure_class);
    }

    public function test_received_format_failure_gets_one_reserved_repair_and_then_is_considered(): void
    {
        $document = $this->cbkStyleDocument();
        $processor = app(SynthesisBatches::class);
        $batch = app(SynthesisCoverage::class)->plan($document)['batches'][0];
        $client = Mockery::mock(AnthropicClient::class);
        $client->shouldReceive('evidenceBatchReservation')->twice()->andReturn(0.025);
        $client->shouldReceive('synthesisReservation')->twice()->andReturn(['synthesis_reserved_usd' => 0.2,
            'repair_reserved_usd' => 0.05]);
        $client->shouldReceive('setRunContext')->zeroOrMoreTimes();
        $client->shouldReceive('summarizeEvidenceBatch')->once()->with($document, $batch, false)
            ->andThrow(new AnthropicStructuredOutputException('malformed_output', 'Malformed JSON.'));
        $client->shouldReceive('summarizeEvidenceBatch')->once()->with($document, $batch, true)
            ->andReturn(['findings' => [], 'invalid_claims' => 0]);

        self::assertSame('processed', $processor->processNext($document, null, $client));
        $unit = DocumentChunk::where('identity', $processor->identity($batch))->sole();
        self::assertSame('pending', $unit->status);
        self::assertSame('malformed_output', $unit->failure_class);
        self::assertSame(count($batch['items']), $unit->result['_failure']['input_count']);
        self::assertSame('processed', $processor->processNext($document, null, $client));
        $unit->refresh();
        self::assertSame('completed', $unit->status);
        self::assertSame(2, $unit->attempts);
        self::assertSame(0.05, (float) $unit->reserved_cost);
        self::assertSame(count($batch['items']), app(EvidenceBudget::class)->forSynthesis($document)['coverage']['synthesis_considered']);
    }

    public function test_internal_validation_is_terminal_and_budget_can_stop_a_format_retry(): void
    {
        $document = $this->cbkStyleDocument();
        $processor = app(SynthesisBatches::class);
        $batch = app(SynthesisCoverage::class)->plan($document)['batches'][0];
        $client = Mockery::mock(AnthropicClient::class);
        $client->shouldReceive('evidenceBatchReservation')->andReturn(0.04);
        $client->shouldReceive('synthesisReservation')->andReturn(['synthesis_reserved_usd' => 0.2,
            'repair_reserved_usd' => 0.05]);
        $client->shouldReceive('setRunContext')->zeroOrMoreTimes();
        $client->shouldReceive('summarizeEvidenceBatch')->once()->andThrow(new \LogicException('Internal parser defect.'));
        self::assertSame('processed', $processor->processNext($document, null, $client));
        $unit = DocumentChunk::where('identity', $processor->identity($batch))->sole();
        self::assertSame('failed', $unit->status);
        self::assertSame('internal_validation', $unit->failure_class);
        self::assertFalse($unit->result['_failure']['recoverable']);
        self::assertSame(count($batch['items']), $unit->result['_failure']['input_count']);
        self::assertSame(0, Artisan::call('docintel:pipeline-report', ['document' => $document->id, '--json' => true]));
        $reported = collect(json_decode(Artisan::output(), true)['synthesis_batches'])->sole();
        self::assertSame($batch['group'], $reported['group']);
        self::assertSame(count($batch['items']), $reported['input_count']);
        self::assertSame('internal_validation', $reported['failure']['code']);

        $second = $this->cbkStyleDocument();
        $second->forceFill(['ai_pipeline' => [...$second->ai_pipeline, 'budget_usd' => 0.3]])->save();
        $next = app(SynthesisCoverage::class)->plan($second)['batches'][0];
        $retryClient = Mockery::mock(AnthropicClient::class);
        $retryClient->shouldReceive('evidenceBatchReservation')->andReturn(0.04);
        $retryClient->shouldReceive('synthesisReservation')->andReturn(['synthesis_reserved_usd' => 0.2,
            'repair_reserved_usd' => 0.05]);
        $retryClient->shouldReceive('setRunContext')->zeroOrMoreTimes();
        $retryClient->shouldReceive('summarizeEvidenceBatch')->once()
            ->andThrow(new AnthropicStructuredOutputException('invalid_schema', 'Missing findings.'));
        self::assertSame('processed', $processor->processNext($second, null, $retryClient));
        self::assertSame('ready', $processor->processNext($second, null, $retryClient));
        $budget = DocumentChunk::where('document_id', $second->id)->where('identity', $processor->identity($next))->sole();
        self::assertSame('budget', $budget->status);
        self::assertSame(1, $budget->attempts);
        self::assertSame('budget_exceeded', $budget->result['_failure']['code']);
    }

    public function test_final_hierarchical_summary_discards_unsupported_prose_and_uses_original_claims(): void
    {
        $this->seed(DocumentSummaryPromptSeederV3::class);
        config(['services.anthropic.synthesis_model' => 'claude-sonnet-5-5']);
        $document = $this->intelligenceDocument('Grounded merge.pdf', 'Processing');
        $document->forceFill(['ai_pipeline' => [...$document->ai_pipeline, 'budget_usd' => 2.0]])->save();
        $input = ['risks' => [], 'deadlines' => [], 'entities' => [], 'facts' => [],
            'kpis' => [['id' => 'kpi:valid', 'type' => 'metric', 'claim' => 'Revenue: KES 10 million',
                'citation' => [['page' => 1, 'start_offset' => 10, 'end_offset' => 20]]]],
            'coverage' => ['hierarchical' => true, 'synthesis_considered' => 799],
            'source_context' => ['coverage' => 'omitted', 'excerpts' => []]];
        Http::fake(['*/messages' => Http::response(['model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 200, 'output_tokens' => 100],
            'content' => [['type' => 'text', 'text' => json_encode([
                'executive_summary' => 'Revenue was KES 1000 million.',
                'key_findings' => ['Revenue was KES 1000 million.'],
                'material_findings' => [['title' => 'Invented revenue', 'category' => 'financial',
                    'explanation' => 'KES 1000 million', 'why_it_matters' => 'Large increase',
                    'severity' => 'high', 'source_ids' => ['kpi:invented']]],
            ])]]])]);
        $result = app(AnthropicClient::class)->generateDocumentSummary(
            json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $document->name, $document);
        self::assertSame('Revenue: KES 10 million [kpi:valid]', $result['executive_summary']);
        self::assertSame([['kpi:valid']], array_column($result['material_findings'], 'source_ids'));
        self::assertSame('Revenue: KES 10 million', $result['material_findings'][0]['explanation']);
        self::assertStringNotContainsString('1000', $result['executive_summary']);
    }

    public function test_duplicate_batch_delivery_waits_and_stale_revision_cannot_claim(): void
    {
        $document = $this->cbkStyleDocument();
        $processor = app(SynthesisBatches::class);
        $batch = app(SynthesisCoverage::class)->plan($document)['batches'][0];
        DocumentChunk::create(['workspace_id' => $document->workspace_id, 'document_id' => $document->id,
            'pipeline_key' => 'pk-test', 'identity' => $processor->identity($batch), 'stage' => 'synthesis_batch',
            'input_hash' => hash('sha256', json_encode($batch)), 'pipeline_version' => '1',
            'prompt_version' => '1', 'status' => 'running', 'attempts' => 1,
            'started_at' => now()]);
        $client = Mockery::mock(AnthropicClient::class);
        $client->shouldNotReceive('summarizeEvidenceBatch');
        self::assertSame('waiting', $processor->processNext($document, null, $client));
        config(['document_intelligence.revision_v1' => true]);
        self::assertSame('stale', $processor->processNext($document, null, $client));
    }

    public function test_continuation_uses_a_protected_final_floor_and_settled_spend(): void
    {
        $this->seed(DocumentSummaryPromptSeederV3::class);
        config(['services.anthropic.synthesis_model' => 'claude-sonnet-5-5']);
        $document = $this->intelligenceDocument('Long continuation.pdf', 'Processing');
        $document->forceFill(['extracted_text' => str_repeat('Financial results and capital adequacy. ', 2500),
            'ai_pipeline' => ['key' => 'pk-test', 'route' => 'incremental', 'synthesis' => 'pending']])->save();
        $client = app(AnthropicClient::class);
        $full = $client->synthesisReservation($document, null, 0);
        $last = $client->synthesisReservation($document, null, 3);
        $model = config('services.anthropic.synthesis_model');
        $batch = app(AiPricing::class)->maximum($model,
            16000 + 4096, (int) config('document_intelligence.synthesis_batch_max_tokens'));
        $floor = $last['synthesis_reserved_usd'] + $last['repair_reserved_usd'] + $batch;
        $fullHold = $full['synthesis_reserved_usd'] + $full['synthesis_degraded_reserved_usd'] + $full['repair_reserved_usd'];
        self::assertLessThan($fullHold, $floor);
        $document->forceFill(['ai_pipeline' => [...$document->ai_pipeline, ...$full,
            'budget_usd' => $floor + 0.061]])->save();
        $pipeline = app(IncrementalPipeline::class);
        self::assertFalse($pipeline->canReserve($document->fresh(), 0.06));
        self::assertTrue($pipeline->canReserve($document->fresh(), 0.06, continuation: true));

        $unit = DocumentChunk::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'pipeline_key' => 'pk-test', 'identity' => 'test-settlement',
            'stage' => 'extraction', 'input_hash' => hash('sha256', 'test-settlement'),
            'pipeline_version' => '1', 'prompt_version' => '1']);
        $pipeline->reserveCost($unit, 0.06);
        self::assertFalse($pipeline->canReserve($document->fresh(), 0.06, continuation: true));
        $pipeline->settleCost($unit, false);
        self::assertSame(0.0, (float) $unit->fresh()->reserved_cost);
        self::assertTrue($pipeline->canReserve($document->fresh(), 0.06, continuation: true));
    }
}
