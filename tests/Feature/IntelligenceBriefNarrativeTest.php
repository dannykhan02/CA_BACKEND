<?php

namespace Tests\Feature;

use App\Jobs\GenerateDocumentSummaryJob;
use App\Jobs\SynthesizeBriefNarrativeJob;
use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\DocumentChunk;
use App\Models\User;
use App\Services\AI\ProviderGate;
use App\Services\AnthropicClient;
use App\Services\Intelligence\B2\BriefReadService;
use App\Services\Intelligence\B2\NarrativeContextBuilder;
use App\Services\Intelligence\B2\NarrativeSynthesizer;
use App\Services\Intelligence\B2\StageASnapshot;
use App\Services\Intelligence\DocumentAnalysisComposer;
use App\Services\Pipeline\PipelineStageRecorder;
use Database\Seeders\DocumentSummaryPromptSeederV3;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsIntelligenceFixtures;
use Tests\TestCase;

/**
 * B2 against stored canonical Stage A evidence, with the fake provider only. Nothing here reaches
 * Anthropic: TestCase::setUp() installs Http::preventStrayRequests(), so an unfaked call fails the
 * test rather than spending.
 */
class IntelligenceBriefNarrativeTest extends TestCase
{
    use BuildsIntelligenceFixtures, RefreshDatabase;

    private const AS_OF = '2026-10-09T00:00:00+00:00';

    protected function setUp(): void
    {
        parent::setUp();
        config(['intelligence_v2.enabled' => true, 'intelligence_v2.b2.enabled' => true,
            'intelligence_v2.b2.model' => 'claude-sonnet-5-5']);
    }

    private function asOf(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::AS_OF);
    }

    /** A Ready incremental document with three grounded metrics and a risk, and budget to spare. */
    private function document(float $budget = 5.0): Document
    {
        $document = $this->intelligenceDocument();
        $this->figure($document, 'Total financing', 'USD 12.4 billion');
        $this->figure($document, 'Core resources', 'USD 4.1 billion');
        $this->figure($document, 'Programme spend', 'USD 7.8 billion');
        $this->riskFinding($document, 'Concentration of exposure in three markets', 'critical');
        $document->forceFill(['ai_pipeline' => [...$document->ai_pipeline, 'budget_usd' => $budget]])->save();

        return $document->fresh();
    }

    /**
     * A grounded currency metric: the stored quote carries both the value and the period, which is
     * what ProvenanceProjector requires before it will call the record document-origin.
     */
    private function figure(Document $document, string $label, string $value, string $period = 'FY2025'): void
    {
        $this->metricFinding($document, $label, $value, 'USD billion', $period,
            ['quote' => $label.': '.$value.' ('.$period.')']);
    }

    /** @param list<array{claim:string,cites:list<string>}> $claims */
    private function fakeNarrative(array $claims, string $stop = 'end_turn'): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['model' => 'claude-sonnet-5-5',
            'stop_reason' => $stop, 'usage' => ['input_tokens' => 2400, 'output_tokens' => 180],
            'content' => [['type' => 'text', 'text' => json_encode(['narrative' => $claims])]]])]);
    }

    /** @return list<array{claim:string,cites:list<string>}> */
    private function groundedClaims(Document $document): array
    {
        $ids = $this->sourceIds($document);

        return [
            ['claim' => 'Total financing reached USD 12.4 billion in FY2025.', 'cites' => [$ids['Total financing']]],
            ['claim' => 'Core resources reached USD 4.1 billion in FY2025.', 'cites' => [$ids['Core resources']]],
        ];
    }

    /** @return array<string,string> */
    private function sourceIds(Document $document): array
    {
        $ids = [];
        foreach (app(StageASnapshot::class)->build($document, StageASnapshot::today())['records'] as $record) {
            $ids[$record['data']['label']] = $record['source_id'];
        }

        return $ids;
    }

    // 1/17. A verified narrative is stored and served alongside B1, which stays intact.
    public function test_verified_narrative_is_stored_and_served_with_the_deterministic_brief(): void
    {
        $document = $this->document();
        $this->fakeNarrative($this->groundedClaims($document));

        $outcome = app(NarrativeSynthesizer::class)->synthesize($document, $this->asOf());
        self::assertSame('verified', $outcome['status']);
        self::assertNull($outcome['fallback_reason']);
        Http::assertSentCount(1);

        $brief = app(BriefReadService::class)->forDocument($document->fresh(), $this->asOf());
        self::assertSame('verified', $brief['status']);
        self::assertNull($brief['fallbackReason']);
        self::assertCount(2, $brief['narrative']['claims']);
        self::assertStringContainsString('USD 12.4 billion', $brief['narrative']['claims'][0]['text']);
        self::assertSame('headline', $brief['blocks'][0]['type']);
        self::assertSame('1', $brief['audit']['contractVersion']);
        self::assertSame('claude-sonnet-5-5', $brief['audit']['model']);

        $unit = DocumentChunk::where('document_id', $document->id)->where('stage', 'brief_synthesis')->sole();
        self::assertSame('completed', $unit->status);
        self::assertSame(64, strlen($unit->input_hash));
        // Audit lineage, but never the raw provider envelope.
        self::assertArrayNotHasKey('content', $unit->result);
        self::assertSame(64, strlen($unit->result['audit']['response_hash']));
        self::assertSame(1, DocumentAiRun::where('purpose', 'brief_synthesis')->count());
        self::assertSame($unit->id, DocumentAiRun::where('purpose', 'brief_synthesis')->value('chunk_id'));
    }

    // B. Idempotency: an unchanged evidence set, contract, prompt, verifier and model must not pay twice.
    public function test_unchanged_inputs_reuse_the_stored_result_without_a_second_call(): void
    {
        $document = $this->document();
        $this->fakeNarrative($this->groundedClaims($document));
        app(NarrativeSynthesizer::class)->synthesize($document, $this->asOf());
        Http::assertSentCount(1);

        $again = app(NarrativeSynthesizer::class)->synthesize($document->fresh(), $this->asOf());
        self::assertSame('verified', $again['status']);
        self::assertFalse($again['provider_called']);
        Http::assertSentCount(1);
        self::assertSame(1, DocumentChunk::where('document_id', $document->id)
            ->where('stage', 'brief_synthesis')->count());

        // A changed prompt version is a different attempt identity, so the stored answer is not reused.
        config(['intelligence_v2.b2.prompt_version' => '2']);
        app(NarrativeSynthesizer::class)->synthesize($document->fresh(), $this->asOf());
        Http::assertSentCount(2);
        self::assertSame(2, DocumentChunk::where('document_id', $document->id)
            ->where('stage', 'brief_synthesis')->count());
    }

    // 14. Verifier rejection stores the reason and serves B1 unchanged.
    public function test_verifier_rejection_degrades_to_the_deterministic_brief(): void
    {
        $document = $this->document();
        $ids = $this->sourceIds($document);
        $this->fakeNarrative([
            ['claim' => 'Total financing reached USD 99.9 billion in FY2025.', 'cites' => [$ids['Total financing']]],
            ['claim' => 'Core resources reached USD 4.1 billion in FY2025.', 'cites' => [$ids['Core resources']]],
        ]);
        $outcome = app(NarrativeSynthesizer::class)->synthesize($document, $this->asOf());
        self::assertSame('rejected', $outcome['status']);
        self::assertSame('verifier_rejected', $outcome['fallback_reason']);

        $brief = app(BriefReadService::class)->forDocument($document->fresh(), $this->asOf());
        self::assertNull($brief['narrative']);
        self::assertSame('rejected', $brief['status']);
        self::assertSame('verifier_rejected', $brief['fallbackReason']);
        self::assertNotEmpty($brief['blocks']);
        $unit = DocumentChunk::where('stage', 'brief_synthesis')->sole();
        self::assertSame('verifier_rejected', $unit->failure_class);
        self::assertContains('brief_numbers_grounded', $unit->result['audit']['verifier_reasons']);
        self::assertSame([], $unit->result['claims']);
    }

    // C/10. A truncated narrative is never salvaged.
    public function test_truncated_output_fails_closed_without_salvaging_a_partial_narrative(): void
    {
        $document = $this->document();
        Http::fake(['api.anthropic.com/*' => Http::response(['model' => 'claude-sonnet-5-5',
            'stop_reason' => 'max_tokens', 'usage' => ['input_tokens' => 2400, 'output_tokens' => 1500],
            'content' => [['type' => 'text', 'text' => '{"narrative":[{"claim":"Total financing reached']]])]);
        $outcome = app(NarrativeSynthesizer::class)->synthesize($document, $this->asOf());
        self::assertSame('failed', $outcome['status']);
        self::assertSame('truncated', $outcome['fallback_reason']);
        Http::assertSentCount(1);

        $brief = app(BriefReadService::class)->forDocument($document->fresh(), $this->asOf());
        self::assertNull($brief['narrative']);
        self::assertSame('failed', $brief['status']);
        self::assertSame('truncated', $brief['fallbackReason']);
        self::assertNotEmpty($brief['blocks']);
    }

    // 15/16. Provider failure and timeout both degrade to B1 and leave no permit held.
    public function test_provider_failure_and_timeout_degrade_to_the_deterministic_brief(): void
    {
        foreach ([
            ['auth', Http::response(['error' => ['type' => 'authentication_error',
                'message' => 'bad key']], 401), 'authentication'],
            ['timeout', fn () => throw new ConnectionException('cURL error 28: Operation timed out'), 'timeout'],
        ] as [$label, $response, $expected]) {
            $document = $this->document();
            Http::fake(['api.anthropic.com/*' => $response]);
            $outcome = app(NarrativeSynthesizer::class)->synthesize($document, $this->asOf());
            self::assertSame('failed', $outcome['status'], $label);
            self::assertSame($expected, $outcome['fallback_reason'], $label);

            $brief = app(BriefReadService::class)->forDocument($document->fresh(), $this->asOf());
            self::assertNull($brief['narrative'], $label);
            self::assertSame($expected, $brief['fallbackReason'], $label);
            self::assertNotEmpty($brief['blocks'], $label);
            // The permit was a lease held by the attempt, not by the result.
            self::assertFalse(app(ProviderGate::class)->holding(), $label);
        }
    }

    // 8/9. Too little or no trusted evidence: nothing is sent, so nothing is paid.
    public function test_empty_and_insufficient_evidence_never_call_the_provider(): void
    {
        $empty = $this->intelligenceDocument();
        $empty->forceFill(['ai_pipeline' => [...$empty->ai_pipeline, 'budget_usd' => 5.0]])->save();
        $outcome = app(NarrativeSynthesizer::class)->synthesize($empty->fresh(), $this->asOf());
        self::assertSame('empty_evidence', $outcome['status']);

        $thin = $this->intelligenceDocument('Thin.pdf');
        $this->figure($thin, 'Total financing', 'USD 12.4 billion');
        $thin->forceFill(['ai_pipeline' => [...$thin->ai_pipeline, 'budget_usd' => 5.0]])->save();
        $outcome = app(NarrativeSynthesizer::class)->synthesize($thin->fresh(), $this->asOf());
        self::assertSame('insufficient_evidence', $outcome['status']);

        Http::assertNothingSent();
        self::assertSame(0, DocumentChunk::where('stage', 'brief_synthesis')->count());
        $brief = app(BriefReadService::class)->forDocument($thin->fresh(), $this->asOf());
        self::assertSame('insufficient_evidence', $brief['status']);
        self::assertNotEmpty($brief['blocks']);
    }

    // B. The per-document ceiling refuses B2 rather than overrunning it.
    public function test_an_exhausted_document_budget_refuses_b2_without_a_call(): void
    {
        $document = $this->document(budget: 0.0);
        $outcome = app(NarrativeSynthesizer::class)->synthesize($document, $this->asOf());
        self::assertSame('unavailable', $outcome['status']);
        self::assertSame('budget_exceeded', $outcome['fallback_reason']);
        Http::assertNothingSent();
        self::assertSame('budget', DocumentChunk::where('stage', 'brief_synthesis')->value('status'));
        self::assertNotEmpty(app(BriefReadService::class)
            ->forDocument($document->fresh(), $this->asOf())['blocks']);
    }

    // 4. Unknown-origin evidence is never sent as factual support, only counted.
    public function test_unknown_origin_evidence_is_excluded_from_the_context_and_counted(): void
    {
        $document = $this->document();
        // A record whose stored value does not occur in its own quote projects as unknown origin.
        $this->evidenceRow($document, 'fact', ['label' => 'Unverified total',
            'value' => 'USD 50 billion', 'quote' => 'The facility was reviewed during the year.'],
            'fact:unverified');

        $built = app(NarrativeContextBuilder::class)
            ->build(app(StageASnapshot::class)->build($document->fresh(), $this->asOf()));
        self::assertNotContains('fact:unverified', $built['supplied']);
        self::assertSame(1, $built['context']['excluded_untrusted_evidence']);
        $encoded = json_encode($built['context']);
        self::assertStringNotContainsString('USD 50 billion', $encoded);
        // No source quote, offset or span id ever reaches the provider.
        self::assertStringNotContainsString('start_offset', $encoded);
        self::assertStringNotContainsString('span_id', $encoded);
        self::assertStringNotContainsString('"quote"', $encoded);
    }

    // F. With B2 off every existing API response is identical to today's.
    public function test_the_api_response_is_unchanged_when_b2_is_off(): void
    {
        $document = $this->document();
        $user = $document->workspace->owner ?? User::whereKey($document->uploaded_by)->sole();
        $this->fakeNarrative($this->groundedClaims($document));
        // Both sides take the default B2 clock, which is what the API read path uses.
        app(NarrativeSynthesizer::class)->synthesize($document);

        config(['intelligence_v2.b2.enabled' => false]);
        $off = $this->actingAs($user)->getJson("/api/documents/{$document->id}/intelligence")
            ->assertOk()->json('data');
        self::assertArrayNotHasKey('brief', $off);

        config(['intelligence_v2.b2.enabled' => true]);
        $on = $this->actingAs($user)->getJson("/api/documents/{$document->id}/intelligence")
            ->assertOk()->json('data');
        self::assertArrayHasKey('brief', $on);
        self::assertSame('verified', $on['brief']['status']);
        // Stage A stamps a wall-clock `as_of` into attention states, so compare the payloads with
        // instants normalized: what must be identical is the structure and every value B2 could
        // have influenced, not the second the response was built.
        $stable = static fn (?array $analysis) => preg_replace(
            '/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:[+-]\d{2}:\d{2}|Z)/',
            '<instant>', json_encode($analysis));
        self::assertSame($stable($off['analysis']), $stable($on['analysis']));
        unset($on['brief']);
        self::assertSame(array_keys($off), array_keys($on));
    }

    // 26/36. The canonical contract, not the extraction source, is what B2 depends on.
    public function test_changing_the_upstream_extraction_source_needs_no_b2_change(): void
    {
        $document = $this->document();
        $base = app(StageASnapshot::class)->build($document, $this->asOf());
        $first = app(NarrativeContextBuilder::class)->build($base);

        // Same canonical records, re-declared as having come from a different extraction prompt and
        // pipeline version: the B2 context and its attempt identity are unchanged.
        $document->forceFill(['ai_pipeline' => [...$document->ai_pipeline,
            'prompt_version' => 'control-minus-materiality-v1', 'pipeline_version' => '99',
            'mode' => 'compact_collector']])->save();
        $second = app(NarrativeContextBuilder::class)
            ->build(app(StageASnapshot::class)->build($document->fresh(), $this->asOf()));

        self::assertSame($first['context'], $second['context']);
        // Byte-identical, not merely equal: the serialized payload is what is sent and what the
        // attempt identity is computed over, so key order counts.
        self::assertSame(
            json_encode($first['context'], JSON_UNESCAPED_UNICODE),
            json_encode($second['context'], JSON_UNESCAPED_UNICODE));
        self::assertSame(
            app(NarrativeContextBuilder::class)->inputHash($first['context'], 'claude-sonnet-5-5'),
            app(NarrativeContextBuilder::class)->inputHash($second['context'], 'claude-sonnet-5-5'));
    }

    /** The attempt identity must be stable across reads within a day, or nothing is ever reused. */
    public function test_the_attempt_identity_is_stable_across_reads_within_a_day(): void
    {
        $document = $this->document();
        $contexts = app(NarrativeContextBuilder::class);
        $first = $contexts->build(app(StageASnapshot::class)->build($document, StageASnapshot::today()));
        $later = $contexts->build(app(StageASnapshot::class)->build($document,
            StageASnapshot::today()->modify('+7 hours')));
        self::assertSame(
            $contexts->inputHash($first['context'], 'claude-sonnet-5-5'),
            $contexts->inputHash($later['context'], 'claude-sonnet-5-5'));
        self::assertSame(StageASnapshot::today()->format('Y-m-d'), $first['context']['as_of']);
    }

    public function test_context_is_deterministic_and_bounded(): void
    {
        $document = $this->document();
        $snapshot = app(StageASnapshot::class)->build($document, $this->asOf());
        $a = app(NarrativeContextBuilder::class)->build($snapshot);
        $b = app(NarrativeContextBuilder::class)->build(
            app(StageASnapshot::class)->build($document->fresh(), $this->asOf()));
        self::assertSame($a['context'], $b['context']);
        self::assertSame($a['supplied'], $b['supplied']);

        // The token budget binds: a tiny budget drops the lowest-priority records, never the first.
        config(['intelligence_v2.b2.context_token_budget' => 220]);
        $bounded = app(NarrativeContextBuilder::class)->build($snapshot);
        self::assertLessThan(count($a['supplied']), count($bounded['supplied']));
        self::assertGreaterThan(0, $bounded['omitted']);
        self::assertLessThanOrEqual(220,
            app(NarrativeContextBuilder::class)->tokens($bounded['context']));
        self::assertSame(array_slice($a['supplied'], 0, count($bounded['supplied'])), $bounded['supplied']);
    }

    /** The snapshot must agree with Stage A's own composer about what is tier 1. */
    public function test_snapshot_agrees_with_the_stage_a_composer(): void
    {
        $document = $this->document();
        $analysis = app(DocumentAnalysisComposer::class)->compose($document, $this->asOf());
        $snapshot = app(StageASnapshot::class)->build($document, $this->asOf());
        $tier1 = array_values(array_filter(array_map(
            static fn (array $record) => ($snapshot['assignments'][$record['identity']]['tier'] ?? null) === 1
                ? $record['source_id'] : null, $snapshot['records'])));
        sort($tier1);
        $composed = array_column($analysis['tier1'], 'sourceId');
        sort($composed);
        self::assertSame($composed, $tier1);
        self::assertSame($analysis['attention']['coverage']['state'], $snapshot['coverage']['state']);
    }

    /**
     * E. The purpose-constraint migration is reversible. `up()` is already proven by every test in
     * this class that records a brief_synthesis run; this covers `down()` and re-application, inside
     * the test transaction so the schema is restored either way.
     */
    public function test_the_purpose_constraint_migration_is_reversible(): void
    {
        $migration = require base_path('database/migrations/2026_10_09_000001_allow_brief_synthesis_ai_runs.php');
        $document = $this->document();
        $run = fn (string $purpose) => DocumentAiRun::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'purpose' => $purpose, 'provider' => 'anthropic',
            'model' => 'claude-sonnet-5-5', 'status' => 'success']);

        $migration->down();
        try {
            // A rejected insert aborts the surrounding transaction, so take a savepoint first.
            DB::transaction(fn () => $run('brief_synthesis'));
            self::fail('The rolled-back constraint still accepted brief_synthesis.');
        } catch (QueryException $e) {
            self::assertStringContainsString('document_ai_runs_purpose_check', $e->getMessage());
        }
        self::assertNotNull($run('document_summary')->id, 'Rollback must not reject existing purposes.');

        $migration->up();
        self::assertNotNull($run('brief_synthesis')->id);
        self::assertNotNull($run('document_summary')->id);

        // down() refuses to discard audit history.
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    /**
     * L. The one production dispatch site, both ways. Without this the flag-guarded branch in
     * GenerateDocumentSummaryJob would never be executed by any test.
     */
    public function test_the_flag_gates_the_only_production_dispatch_site(): void
    {
        foreach ([true, false] as $enabled) {
            Bus::fake([SynthesizeBriefNarrativeJob::class]);
            config(['intelligence_v2.b2.enabled' => $enabled]);
            $this->seed(DocumentSummaryPromptSeederV3::class);
            $document = $this->document();
            // Credits and accounting are not what this test is about: grant them so the Stage A
            // summary stage can complete and reach the dispatch site.
            $document->workspace->credits()->update(['documents_remaining' => 5]);
            $document->forceFill(['status' => 'Processing', 'credit_accounted_at' => now(),
                'ai_pipeline' => [...$document->ai_pipeline, 'synthesis' => 'pending']])->save();
            Http::fake(['api.anthropic.com/*' => Http::response(['model' => 'claude-sonnet-5-5',
                'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 900, 'output_tokens' => 200],
                'content' => [['type' => 'text', 'text' => json_encode([
                    'executive_summary' => 'Total financing grew over the period.',
                    'key_findings' => ['Total financing grew.'], 'critical_risks' => [],
                    'upcoming_deadlines' => [], 'important_entities' => [],
                    'recommended_attention' => [], 'executive_assessment' => null,
                    'material_findings' => [], 'trends' => [], 'tensions' => [], 'questions' => [],
                ])]]])]);

            (new GenerateDocumentSummaryJob($document->id, true))
                ->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));

            self::assertSame('Ready', $document->fresh()->status, 'Stage A must reach Ready either way.');
            if ($enabled) {
                Bus::assertDispatched(SynthesizeBriefNarrativeJob::class,
                    fn ($job) => $job->documentId === $document->id && $job->queue === 'synthesis');
            } else {
                Bus::assertNotDispatched(SynthesizeBriefNarrativeJob::class);
            }
        }
    }

    public function test_the_job_is_a_no_op_while_the_flag_is_off(): void
    {
        config(['intelligence_v2.b2.enabled' => false]);
        $document = $this->document();
        app(SynthesizeBriefNarrativeJob::class, ['documentId' => $document->id])
            ->handle(app(NarrativeSynthesizer::class));
        Http::assertNothingSent();
        self::assertSame(0, DocumentChunk::where('stage', 'brief_synthesis')->count());
        self::assertNull(app(BriefReadService::class)->forDocument($document, $this->asOf()));
    }
}
