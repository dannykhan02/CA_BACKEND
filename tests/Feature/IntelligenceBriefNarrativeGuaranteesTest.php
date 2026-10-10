<?php

namespace Tests\Feature;

use App\Jobs\SynthesizeBriefNarrativeJob;
use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\DocumentChunk;
use App\Models\User;
use App\Services\Intelligence\B2\BriefReadService;
use App\Services\Intelligence\B2\NarrativeCheckpoint;
use App\Services\Intelligence\B2\NarrativeContextBuilder;
use App\Services\Intelligence\B2\NarrativeSynthesizer;
use App\Services\Intelligence\B2\StageASnapshot;
use App\Services\Intelligence\Brief\BriefAssembler;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsIntelligenceFixtures;
use Tests\TestCase;

/**
 * The guarantees a reader depends on, as opposed to the mechanics of one attempt
 * (IntelligenceBriefNarrativeTest) or the verifier's own judgement
 * (IntelligenceNarrativeVerifierTest):
 *
 *  - a read never reaches the provider and never costs anything, whatever B2's state;
 *  - an attempt identity is claimed once, so a refresh, a concurrent worker, a failure or a
 *    rejection cannot turn into a second paid call;
 *  - B1's deterministic blocks are byte-identical whatever B2 did, which is the invariant that
 *    catches an integration or merge change silently altering B1;
 *  - a job that raises leaves the Brief readable and exposes nothing partial.
 *
 * Every case runs against the fake provider under Http::preventStrayRequests()
 * (Tests\TestCase::setUp), so an unfaked request fails the test rather than spending.
 */
class IntelligenceBriefNarrativeGuaranteesTest extends TestCase
{
    use BuildsIntelligenceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['intelligence_v2.enabled' => true, 'intelligence_v2.b2.enabled' => true,
            'intelligence_v2.b2.model' => 'claude-sonnet-5-5']);
    }

    /** A Ready incremental document with four grounded records and budget to spare. */
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

    private function figure(Document $document, string $label, string $value, string $period = 'FY2025'): void
    {
        $this->metricFinding($document, $label, $value, 'USD billion', $period,
            ['quote' => $label.': '.$value.' ('.$period.')']);
    }

    /** @param list<array{claim:string,cites:list<string>}> $claims */
    private function fakeNarrative(array $claims): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['model' => 'claude-sonnet-5-5',
            'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 2400, 'output_tokens' => 180],
            'content' => [['type' => 'text', 'text' => json_encode(['narrative' => $claims])]]])]);
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

    /** @return list<array{claim:string,cites:list<string>}> */
    private function groundedClaims(Document $document): array
    {
        $ids = $this->sourceIds($document);

        return [
            ['claim' => 'Total financing reached USD 12.4 billion in FY2025.', 'cites' => [$ids['Total financing']]],
            ['claim' => 'Core resources reached USD 4.1 billion in FY2025.', 'cites' => [$ids['Core resources']]],
        ];
    }

    private function owner(Document $document): User
    {
        return User::whereKey($document->uploaded_by)->sole();
    }

    private function brief(Document $document): array
    {
        return app(BriefReadService::class)->forDocument($document->fresh());
    }

    /**
     * Every state the read path has to answer from. One case per test run, because repeated
     * Http::fake() calls merge their stubs and the first match wins — a loop inside one test would
     * silently keep the first state's provider response for all the later ones.
     *
     * @return array<string,array{0:string}>
     */
    public static function b2States(): array
    {
        return [
            'not generated' => ['not_generated'],
            'verified' => ['verified'],
            'verifier rejected' => ['rejected'],
            'provider failure' => ['failed'],
            'malformed output' => ['malformed'],
            'budget denied' => ['budget'],
        ];
    }

    /** The two terminal outcomes a refresh must never turn into a second paid call. */
    public static function terminalB2States(): array
    {
        return ['verifier rejected' => ['rejected', 'verifier_rejected'],
            'provider failure' => ['failed', 'authentication']];
    }

    /** Puts a document into one B2 state, and reports whether the attempt sent a request. */
    private function arrange(string $state): Document
    {
        $document = $state === 'budget' ? $this->document(budget: 0.0) : $this->document();
        match ($state) {
            'verified' => $this->fakeNarrative($this->groundedClaims($document)),
            'rejected' => $this->fakeNarrative([
                ['claim' => 'Total financing reached USD 99.9 billion in FY2025.',
                    'cites' => [$this->sourceIds($document)['Total financing']]],
                ['claim' => 'Core resources reached USD 4.1 billion in FY2025.',
                    'cites' => [$this->sourceIds($document)['Core resources']]],
            ]),
            'failed' => Http::fake(['api.anthropic.com/*' => Http::response(
                ['error' => ['type' => 'authentication_error', 'message' => 'bad key']], 401)]),
            'malformed' => Http::fake(['api.anthropic.com/*' => Http::response([
                'model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 2400, 'output_tokens' => 20],
                'content' => [['type' => 'text', 'text' => 'not json at all']]])]),
            default => Http::fake(),
        };
        if ($state !== 'not_generated') {
            app(NarrativeSynthesizer::class)->synthesize($document);
        }

        return $document->fresh();
    }

    /** Whether the attempt for this state left a request with the provider. */
    private function requestsFromAttempt(string $state): int
    {
        return in_array($state, ['verified', 'rejected', 'failed', 'malformed'], true) ? 1 : 0;
    }

    // ---------------------------------------------------------------- read path (spec section 4)

    /**
     * The read path must be free and provider-free in every B2 state, including the state a reader
     * hits most often: B2 on, nothing synthesized yet. A GET that could synthesize would make the
     * cost of the feature a function of traffic rather than of documents.
     */
    public function test_a_read_never_reaches_the_provider_and_never_costs_anything(): void
    {
        Bus::fake();
        Http::fake();
        $document = $this->document();
        $user = $this->owner($document);

        for ($i = 0; $i < 3; $i++) {
            $payload = $this->actingAs($user)->getJson("/api/documents/{$document->id}/intelligence")
                ->assertOk()->json('data');
            self::assertArrayHasKey('brief', $payload);
            self::assertNull($payload['brief']['narrative']);
            self::assertSame('not_generated', $payload['brief']['status']);
            self::assertNotEmpty($payload['brief']['blocks']);
        }

        // Nothing sent, nothing claimed, nothing charged, and no synthesis work queued by a read.
        Http::assertNothingSent();
        self::assertSame(0, DocumentChunk::where('stage', 'brief_synthesis')->count());
        self::assertSame(0, DocumentAiRun::where('purpose', 'brief_synthesis')->count());
        Bus::assertNotDispatched(SynthesizeBriefNarrativeJob::class);
    }

    /**
     * The same guarantee in every B2 state, including the ones where a naive implementation would be
     * tempted to "repair" on read. A read adds no request, no unit and no queued work to any of them.
     */
    #[DataProvider('b2States')]
    public function test_no_b2_state_lets_a_read_reach_the_provider(string $state): void
    {
        Bus::fake();
        $document = $this->arrange($state);
        $sent = $this->requestsFromAttempt($state);
        Http::assertSentCount($sent);
        $units = DocumentChunk::where('stage', 'brief_synthesis')->count();

        $user = $this->owner($document);
        for ($i = 0; $i < 3; $i++) {
            $brief = $this->actingAs($user)->getJson("/api/documents/{$document->id}/intelligence")
                ->assertOk()->json('data.brief');
            self::assertNotEmpty($brief['blocks'], $state);
            $state === 'verified'
                ? self::assertSame('verified', $brief['status'])
                : self::assertNull($brief['narrative'], $state);
        }

        Http::assertSentCount($sent);
        self::assertSame($units, DocumentChunk::where('stage', 'brief_synthesis')->count(), $state);
        Bus::assertNotDispatched(SynthesizeBriefNarrativeJob::class);
    }

    // ------------------------------------------------------- idempotency contract (spec section 5)

    /** A. Same input, same versions, read repeatedly: the stored narrative is reused as-is. */
    public function test_repeated_reads_of_a_verified_narrative_make_no_further_call(): void
    {
        $document = $this->document();
        $this->fakeNarrative($this->groundedClaims($document));
        app(NarrativeSynthesizer::class)->synthesize($document);
        Http::assertSentCount(1);

        $user = $this->owner($document);
        for ($i = 0; $i < 3; $i++) {
            $brief = $this->actingAs($user)->getJson("/api/documents/{$document->id}/intelligence")
                ->assertOk()->json('data.brief');
            self::assertSame('verified', $brief['status']);
            self::assertCount(2, $brief['narrative']['claims']);
        }
        Http::assertSentCount(1);
        self::assertSame(1, DocumentAiRun::where('purpose', 'brief_synthesis')->count());
    }

    /**
     * B. Two workers racing for the same attempt identity.
     *
     * Atomic mechanism, both halves of it: NarrativeCheckpoint::claim() runs in a transaction that
     * takes `select ... for update` on the document row, and the unit it then creates is a
     * `document_chunks` row under the unique index (document_id, pipeline_key, identity) added by
     * 2026_10_04_000003_add_incremental_document_processing. The row lock serializes two claims for
     * the same document; the unique index is what makes a claim for the same identity resolve to the
     * same row rather than a second one. IncrementalPipeline::reserveCost() then moves that row to
     * `running`, which is the in-flight marker a second claim refuses on.
     *
     * The second worker arrives while the first is inside its provider call, which is the only
     * window where double payment is possible.
     */
    public function test_a_concurrent_attempt_for_the_same_identity_makes_no_second_call(): void
    {
        $document = $this->document();
        $claims = $this->groundedClaims($document);
        $nested = null;

        Http::fake(['api.anthropic.com/*' => function () use ($document, $claims, &$nested) {
            // A second worker picks up the same document mid-flight.
            $nested = app(NarrativeSynthesizer::class)->synthesize($document->fresh());

            return Http::response(['model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 2400, 'output_tokens' => 180],
                'content' => [['type' => 'text', 'text' => json_encode(['narrative' => $claims])]]]);
        }]);

        $first = app(NarrativeSynthesizer::class)->synthesize($document);

        self::assertSame('verified', $first['status']);
        self::assertTrue($first['provider_called']);
        self::assertNotNull($nested, 'the nested attempt did not run');
        self::assertFalse($nested['provider_called']);
        self::assertSame('in_flight', $nested['fallback_reason']);

        // Exactly one request, one unit and one audit row for the two attempts.
        Http::assertSentCount(1);
        self::assertSame(1, DocumentChunk::where('document_id', $document->id)
            ->where('stage', 'brief_synthesis')->count());
        self::assertSame(1, DocumentAiRun::where('purpose', 'brief_synthesis')->count());
    }

    /** The unique index the claim relies on is really there, and really refuses a duplicate. */
    public function test_the_attempt_identity_is_unique_per_document_and_pipeline(): void
    {
        $document = $this->document();
        $built = app(NarrativeContextBuilder::class)
            ->build(app(StageASnapshot::class)->build($document, StageASnapshot::today()));
        $hash = app(NarrativeContextBuilder::class)->inputHash($built['context'], 'claude-sonnet-5-5');
        $claim = app(NarrativeCheckpoint::class)->claim($document, $hash, $built['eligible'], 'claude-sonnet-5-5');
        self::assertNotNull($claim['unit']);

        $this->expectException(QueryException::class);
        DB::table('document_chunks')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $document->workspace_id, 'document_id' => $document->id,
            'pipeline_key' => $document->ai_pipeline['key'], 'identity' => 'brief_synthesis:'.$hash,
            'stage' => 'brief_synthesis', 'status' => 'pending', 'input_hash' => $hash,
            'attempts' => 0, 'reserved_cost' => 0,
        ]);
    }

    /** C. A changed evidence set is a different identity, so a new synthesis is allowed. */
    public function test_a_changed_evidence_set_allows_a_new_synthesis(): void
    {
        $document = $this->document();
        $this->fakeNarrative($this->groundedClaims($document));
        app(NarrativeSynthesizer::class)->synthesize($document);
        Http::assertSentCount(1);

        // One more grounded record: the Stage A projection changes, so the context does.
        $this->figure($document, 'Administrative spend', 'USD 0.6 billion');
        $document->touch();
        // StageASnapshot is bound `scoped` and memoizes on the document's own updated_at, which
        // Laravel stores at second precision. Forgetting the scoped instances is what the next
        // request or queue job does, and is the boundary at which a changed evidence set is seen.
        $this->app->forgetScopedInstances();
        app(NarrativeSynthesizer::class)->synthesize($document->fresh());

        Http::assertSentCount(2);
        self::assertSame(2, DocumentChunk::where('document_id', $document->id)
            ->where('stage', 'brief_synthesis')->count());
    }

    /**
     * E/F. A failed or rejected attempt must not be retried by a read, and must not be retried by
     * another synthesize() for the same identity either: retry is the job's explicit policy
     * (intelligence_v2.b2.attempts), never a consequence of somebody refreshing the page.
     */
    #[DataProvider('terminalB2States')]
    public function test_a_terminal_attempt_is_never_retried_by_a_read(string $state, string $reason): void
    {
        $document = $this->arrange($state);
        Http::assertSentCount(1);

        $user = $this->owner($document);
        for ($i = 0; $i < 3; $i++) {
            $brief = $this->actingAs($user)->getJson("/api/documents/{$document->id}/intelligence")
                ->assertOk()->json('data.brief');
            self::assertNull($brief['narrative'], $state);
            self::assertSame($reason, $brief['fallbackReason'], $state);
            self::assertNotEmpty($brief['blocks'], $state);
        }
        Http::assertSentCount(1);

        // Nor does a second synthesize() for the same identity send anything: the unit is no longer
        // claimable, so retry can only come from the job's own bounded attempt policy.
        $again = app(NarrativeSynthesizer::class)->synthesize($document->fresh());
        self::assertFalse($again['provider_called'], $state);
        Http::assertSentCount(1);
        self::assertSame(1, DocumentChunk::where('document_id', $document->id)
            ->where('stage', 'brief_synthesis')->count(), $state);
    }

    // ------------------------------------------- B1 semantic invariant (spec sections 7 and 8)

    /**
     * B1's blocks are byte-identical to what BriefAssembler produces for the same canonical Stage A
     * input, in every B2 state and with B2 off. This is the invariant that catches an integration or
     * merge change altering B1 by accident: B2 may only ever add a sibling key, never touch these
     * bytes.
     */
    #[DataProvider('b2States')]
    public function test_b1_blocks_are_byte_identical_whatever_b2_did(string $state): void
    {
        $document = $this->arrange($state);
        $asOf = StageASnapshot::today();
        $snapshot = app(StageASnapshot::class)->build($document, $asOf);

        // The deterministic B1 Brief, assembled with no knowledge of B2 at all. Block ids embed the
        // record identities of the document they came from, so the comparison is per document: what
        // must not move is B1's own output for a given canonical Stage A input.
        $pure = app(BriefAssembler::class)->assemble($snapshot['document_name'],
            $snapshot['document_type'], $snapshot['records'], $snapshot['assignments'],
            $snapshot['coverage'], $asOf, $snapshot['forced_overflow']);

        $served = app(BriefReadService::class)->forDocument($document, $asOf);
        self::assertSame(json_encode($pure['blocks']), json_encode($served['blocks']), $state);
        self::assertSame($pure['template_version'], $served['templateVersion'], $state);
        self::assertFalse($pure['ai_blocks_available'], $state);

        // The flag-off half, testable since B1 was wired to the API (343593f): with B2 off the
        // served Brief is still B1, and still byte-identical to BriefAssembler's own output. This
        // is the invariant that catches an integration change altering B1 by accident.
        config(['intelligence_v2.b2.enabled' => false]);
        $off = app(BriefReadService::class)->forDocument($document->fresh(), $asOf);
        self::assertSame(json_encode($pure['blocks']), json_encode($off['blocks']), $state);
        self::assertSame($pure['template_version'], $off['templateVersion'], $state);
        self::assertSame('disabled', $off['status'], $state);
        self::assertSame('disabled', $off['fallbackReason'], $state);
        self::assertNull($off['narrative'], $state);
        self::assertNull($off['audit'], $state);

        // And with the V2 Brief itself off there is no key at all.
        config(['intelligence_v2.brief.enabled' => false]);
        self::assertNull(app(BriefReadService::class)->forDocument($document->fresh(), $asOf), $state);
    }

    /**
     * The same invariant through the HTTP API rather than the service, because that is what a
     * reader actually receives: with B2 off, `data.brief.blocks` is byte-identical to B1's own
     * output, and turning B2 on cannot change those bytes.
     */
    public function test_the_api_serves_byte_identical_b1_blocks_with_b2_off_and_on(): void
    {
        $document = $this->arrange('verified');
        $user = $this->owner($document);
        $asOf = StageASnapshot::today();
        $snapshot = app(StageASnapshot::class)->build($document, $asOf);
        $pure = app(BriefAssembler::class)->assemble($snapshot['document_name'],
            $snapshot['document_type'], $snapshot['records'], $snapshot['assignments'],
            $snapshot['coverage'], $asOf, $snapshot['forced_overflow']);

        config(['intelligence_v2.b2.enabled' => false]);
        $off = $this->actingAs($user)->getJson("/api/documents/{$document->id}/intelligence")
            ->assertOk()->json('data.brief');

        config(['intelligence_v2.b2.enabled' => true]);
        $on = $this->actingAs($user)->getJson("/api/documents/{$document->id}/intelligence")
            ->assertOk()->json('data.brief');

        self::assertSame(json_encode($pure['blocks']), json_encode($off['blocks']));
        self::assertSame(json_encode($off['blocks']), json_encode($on['blocks']));
        self::assertSame('disabled', $off['status']);
        self::assertNull($off['narrative']);
        // B2 on only adds the narrative beside those same bytes.
        self::assertSame('verified', $on['status']);
        self::assertCount(2, $on['narrative']['claims']);
    }

    // ------------------------------------------------- fault injection (spec sections 8 and 9)

    /**
     * An exception raised inside the job, rather than a provider error the synthesizer handles.
     *
     * This is the case the synthesizer cannot settle itself, so the job's failed() handler is the
     * only thing standing between a crash and a unit stuck `running` forever — which would read as
     * a live attempt and make B2 permanently unavailable for this evidence set.
     */
    public function test_an_exception_inside_the_job_leaves_the_brief_readable_and_nothing_partial(): void
    {
        Http::fake();
        $document = $this->document();

        // A synthesizer that raises after the unit is claimed and marked running, exactly as a
        // worker killed mid-attempt would leave it.
        $built = app(NarrativeContextBuilder::class)
            ->build(app(StageASnapshot::class)->build($document, StageASnapshot::today()));
        $hash = app(NarrativeContextBuilder::class)->inputHash($built['context'], 'claude-sonnet-5-5');
        app(NarrativeCheckpoint::class)->claim($document, $hash, $built['eligible'], 'claude-sonnet-5-5');
        self::assertSame('running', DocumentChunk::where('stage', 'brief_synthesis')->value('status'));

        $this->app->bind(NarrativeSynthesizer::class, function () {
            throw new \RuntimeException('worker died mid-attempt');
        });
        $job = new SynthesizeBriefNarrativeJob($document->id);
        $raised = null;
        try {
            $this->app->call([$job, 'handle']);
        } catch (\Throwable $e) {
            $raised = $e;
        }
        self::assertInstanceOf(\RuntimeException::class, $raised);
        // What the queue does with a job that raised.
        $job->failed($raised);

        $unit = DocumentChunk::where('stage', 'brief_synthesis')->sole();
        self::assertSame('uncertain', $unit->status);
        self::assertSame('worker_timeout', $unit->failure_class);
        // Nothing partial is stored, so nothing partial can be exposed.
        self::assertNull($unit->result);

        $this->app->forgetInstance(NarrativeSynthesizer::class);
        $this->app->offsetUnset(NarrativeSynthesizer::class);
        $brief = $this->brief($document);
        self::assertNull($brief['narrative']);
        self::assertSame('uncertain', $brief['status']);
        self::assertSame('worker_timeout', $brief['fallbackReason']);
        self::assertNotEmpty($brief['blocks']);
        // The document itself was never touched by B2.
        self::assertSame('Ready', $document->fresh()->status);
        Http::assertNothingSent();
    }

    /** Malformed structured output is a failure, never a partial narrative. */
    public function test_malformed_provider_output_degrades_to_the_deterministic_brief(): void
    {
        $document = $this->document();
        Http::fake(['api.anthropic.com/*' => Http::response(['model' => 'claude-sonnet-5-5',
            'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 2400, 'output_tokens' => 24],
            'content' => [['type' => 'text', 'text' => '{"narrative":"a sentence, not a list"}']]])]);

        $outcome = app(NarrativeSynthesizer::class)->synthesize($document);
        self::assertContains($outcome['status'], ['failed', 'rejected']);

        $brief = $this->brief($document);
        self::assertNull($brief['narrative']);
        self::assertNotEmpty($brief['blocks']);
        $unit = DocumentChunk::where('stage', 'brief_synthesis')->sole();
        self::assertSame([], $unit->result['claims'] ?? []);
    }

    // ------------------------------------------------------ budget denial (spec section 10)

    /**
     * A realistically small document: its budget is the real formula's floor
     * (document_intelligence.budget_base_usd, $0.50 for a short document), and extraction plus
     * Stage A synthesis have already committed almost all of it. B2 asks for what is left, is
     * refused, and nothing is sent.
     */
    public function test_a_small_document_whose_budget_is_spent_denies_b2_before_any_call(): void
    {
        Http::fake();
        $document = $this->intelligenceDocument('Two page letter.pdf');
        $this->figure($document, 'Total financing', 'USD 12.4 billion');
        $this->figure($document, 'Core resources', 'USD 4.1 billion');
        $this->riskFinding($document, 'Single-supplier dependency', 'high');

        // The real formula for a short document, not an invented number.
        $budget = min((float) config('document_intelligence.budget_max_usd'),
            (float) config('document_intelligence.budget_base_usd')
                + strlen((string) $document->extracted_text) / 3 / 1000
                    * (float) config('document_intelligence.budget_per_1000_tokens_usd'));
        self::assertSame(0.5, round($budget, 2), 'a short document sits on the budget floor');
        $document->forceFill(['ai_pipeline' => [...$document->ai_pipeline, 'budget_usd' => $budget]])->save();

        // Extraction and Stage A synthesis have already committed all but a cent of it.
        DocumentChunk::create([
            'workspace_id' => $document->workspace_id, 'document_id' => $document->id,
            'pipeline_key' => $document->ai_pipeline['key'], 'identity' => 'extraction:1',
            'stage' => 'extraction', 'status' => 'completed', 'input_hash' => str_repeat('a', 64),
            'pipeline_version' => (string) config('document_intelligence.pipeline_version'),
            'prompt_version' => '1',
            'attempts' => 1, 'reserved_cost' => round($budget - 0.01, 6), 'completed_at' => now(),
        ]);

        $outcome = app(NarrativeSynthesizer::class)->synthesize($document->fresh());

        self::assertSame('unavailable', $outcome['status']);
        self::assertSame('budget_exceeded', $outcome['fallback_reason']);
        // Denied before the provider, so nothing was sent and nothing settled.
        Http::assertNothingSent();
        self::assertSame(0, DocumentAiRun::where('purpose', 'brief_synthesis')->count());
        $unit = DocumentChunk::where('stage', 'brief_synthesis')->sole();
        self::assertSame('budget', $unit->status);
        self::assertSame('budget_exceeded', $unit->failure_class);
        self::assertSame(0.0, (float) $unit->reserved_cost);
        self::assertNull($unit->result);

        $brief = $this->brief($document);
        self::assertNull($brief['narrative']);
        self::assertSame('budget', $brief['status']);
        self::assertNotEmpty($brief['blocks']);
    }
}
