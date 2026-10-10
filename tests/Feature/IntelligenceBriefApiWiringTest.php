<?php

namespace Tests\Feature;

use App\Jobs\SynthesizeBriefNarrativeJob;
use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\DocumentChunk;
use App\Models\User;
use App\Services\AnthropicClient;
use App\Services\Intelligence\B2\NarrativeContextBuilder;
use App\Services\Intelligence\B2\NarrativeSynthesizer;
use App\Services\Intelligence\B2\StageASnapshot;
use App\Services\Intelligence\Brief\BriefAssembler;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\BuildsIntelligenceFixtures;
use Tests\TestCase;

class IntelligenceBriefApiWiringTest extends TestCase
{
    use BuildsIntelligenceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['intelligence_v2.enabled' => true, 'intelligence_v2.brief.enabled' => true,
            'intelligence_v2.b2.enabled' => false, 'intelligence_v2.b2.model' => 'claude-sonnet-5-5']);
        app()->bind(AnthropicClient::class, fn () => throw new \LogicException('GET constructed provider client'));
        app()->bind(NarrativeSynthesizer::class, fn () => throw new \LogicException('GET constructed synthesizer'));
    }

    private function rich(): Document
    {
        $document = $this->intelligenceDocument();
        foreach (['Total financing' => '12.4', 'Core resources' => '4.1', 'Programme spend' => '7.8'] as $label => $value) {
            $this->metricFinding($document, $label, $value, 'USD billion', 'FY2025',
                ['quote' => $label.': '.$value.' USD billion (FY2025)']);
        }
        $this->riskFinding($document, 'Concentration of exposure in three markets', 'critical');

        return $document->fresh();
    }

    private function read(Document $document): array
    {
        return $this->actingAs(User::findOrFail($document->uploaded_by))
            ->getJson("/api/documents/{$document->id}/intelligence")->assertOk()->json('data');
    }

    private function direct(Document $document): array
    {
        $asOf = StageASnapshot::today();
        $snapshot = app(StageASnapshot::class)->build($document->fresh(), $asOf);

        return app(BriefAssembler::class)->assemble($snapshot['document_name'], $snapshot['document_type'],
            $snapshot['records'], $snapshot['assignments'], $snapshot['coverage'], $asOf,
            $snapshot['forced_overflow']);
    }

    private function canonical(mixed $value): string
    {
        $sort = static function (mixed $item) use (&$sort): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }

            return array_map($sort, $item);
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR);
    }

    private function assertDirectEquality(Document $document, array $payload): void
    {
        $direct = $this->direct($document);
        self::assertSame($this->canonical($direct['blocks']), $this->canonical($payload['brief']['blocks']));
        self::assertSame($direct['template_version'], $payload['brief']['templateVersion']);
    }

    private function checkpoint(Document $document, string $status, array $result = [], ?string $failure = null): DocumentChunk
    {
        $snapshot = app(StageASnapshot::class)->build($document, StageASnapshot::today());
        $context = app(NarrativeContextBuilder::class);
        $built = $context->build($snapshot);
        self::assertGreaterThanOrEqual(3, count($built['supplied']));
        $hash = $context->inputHash($built['context'], $context->model());

        return DocumentChunk::create([
            'workspace_id' => $document->workspace_id, 'document_id' => $document->id,
            'pipeline_key' => $document->ai_pipeline['key'], 'identity' => 'brief_synthesis:'.$hash,
            'stage' => 'brief_synthesis', 'input_hash' => $hash, 'pipeline_version' => 'test',
            'prompt_version' => '1', 'status' => $status, 'result' => $result,
            'failure_class' => $failure,
        ]);
    }

    public function test_rich_and_thin_b1_match_the_direct_assembler_with_b2_off(): void
    {
        Bus::fake();
        $beforeRuns = DocumentAiRun::count();
        $beforeCheckpoints = DocumentChunk::where('stage', 'brief_synthesis')->count();
        $rich = $this->rich();
        $richPayload = $this->read($rich);
        self::assertArrayHasKey('brief', $richPayload, 'rich response');
        $this->assertDirectEquality($rich, $richPayload);
        self::assertSame('disabled', $richPayload['brief']['status']);
        self::assertNotEmpty($richPayload['brief']['blocks']);

        $thin = $this->intelligenceDocument('Thin document.pdf');
        $thinPayload = $this->read($thin);
        self::assertArrayHasKey('brief', $thinPayload, 'thin response');
        $this->assertDirectEquality($thin, $thinPayload);
        self::assertSame('disabled', $thinPayload['brief']['status']);
        self::assertNull($thinPayload['brief']['narrative']);
        self::assertSame('headline', $thinPayload['brief']['blocks'][0]['type']);
        self::assertContains('coverage_note', array_column($thinPayload['brief']['blocks'], 'type'));
        config(['intelligence_v2.b2.enabled' => true]);
        $thinWithB2On = $this->read($thin);
        self::assertSame('empty_evidence', $thinWithB2On['brief']['status']);
        self::assertNull($thinWithB2On['brief']['narrative']);
        $this->assertDirectEquality($thin, $thinWithB2On);
        Bus::assertNothingDispatched();
        self::assertSame($beforeRuns, DocumentAiRun::count());
        self::assertSame($beforeCheckpoints, DocumentChunk::where('stage', 'brief_synthesis')->count());
    }

    public function test_no_b2_result_and_repeated_reads_never_construct_or_dispatch_synthesis(): void
    {
        config(['intelligence_v2.b2.enabled' => true]);
        $document = $this->rich();
        Bus::fake();
        $beforeRuns = DocumentAiRun::count();
        $beforeCheckpoints = DocumentChunk::where('stage', 'brief_synthesis')->count();
        for ($i = 0; $i < 10; $i++) {
            $payload = $this->read($document);
            self::assertSame('not_generated', $payload['brief']['status']);
            $this->assertDirectEquality($document, $payload);
        }
        Bus::assertNotDispatched(SynthesizeBriefNarrativeJob::class);
        Bus::assertNothingDispatched();
        self::assertSame($beforeRuns, DocumentAiRun::count());
        self::assertSame($beforeCheckpoints, DocumentChunk::where('stage', 'brief_synthesis')->count());
    }

    public function test_only_verified_b2_claims_are_exposed_and_transitions_are_fresh(): void
    {
        config(['intelligence_v2.b2.enabled' => true]);
        $document = $this->rich();
        $before = $this->read($document);
        self::assertSame('not_generated', $before['brief']['status']);
        $this->checkpoint($document, 'completed', ['status' => 'verified',
            'claims' => [['text' => 'Verified marker in admitted narrative.', 'cites' => []]]]);
        $after = $this->read($document);
        self::assertSame('verified', $after['brief']['status']);
        self::assertSame('Verified marker in admitted narrative.', $after['brief']['narrative']['claims'][0]['text']);
        self::assertSame($before['brief']['blocks'], $after['brief']['blocks']);
        $this->assertDirectEquality($document, $after);
    }

    public function test_rejected_failed_and_in_progress_content_never_leak_or_retry(): void
    {
        config(['intelligence_v2.b2.enabled' => true]);
        foreach ([
            ['completed', 'rejected', 'verifier_rejected'],
            ['failed', 'failed', 'provider_error'],
            ['failed', 'failed', 'timeout'],
            ['failed', 'failed', 'malformed_output'],
            ['uncertain', 'uncertain', 'worker_timeout'],
            ['budget', 'budget', 'budget_exceeded'],
            ['running', 'running', null],
        ] as [$unitStatus, $apiStatus, $failure]) {
            $document = $this->rich();
            $marker = 'UNTRUSTED_B2_'.$document->id;
            $this->checkpoint($document, $unitStatus, ['status' => $unitStatus === 'completed' ? 'rejected' : 'failed',
                'claims' => [['text' => $marker, 'cites' => []]]], $failure);
            Bus::fake();
            $beforeRuns = DocumentAiRun::count();
            $beforeUnits = DocumentChunk::where('stage', 'brief_synthesis')->count();
            for ($i = 0; $i < 10; $i++) {
                $payload = $this->read($document);
                self::assertSame($apiStatus, $payload['brief']['status']);
                self::assertNull($payload['brief']['narrative']);
                self::assertStringNotContainsString($marker, json_encode($payload['brief']));
                $this->assertDirectEquality($document, $payload);
            }
            Bus::assertNothingDispatched();
            self::assertSame($beforeRuns, DocumentAiRun::count());
            self::assertSame($beforeUnits, DocumentChunk::where('stage', 'brief_synthesis')->count());
        }
    }

    public function test_one_get_projects_stage_a_once_and_preserves_existing_response_fields(): void
    {
        $document = $this->rich();
        config(['intelligence_v2.brief.enabled' => false]);
        $before = $this->read($document);
        self::assertArrayNotHasKey('brief', $before);
        self::assertSame(['name' => 'Annual report.pdf', 'type' => 'PDF', 'status' => 'Ready'],
            array_intersect_key($before['document'], array_flip(['name', 'type', 'status'])));
        self::assertSame(['entities' => 0, 'risks' => 1, 'deadlines' => 0], $before['summary']);
        config(['intelligence_v2.brief.enabled' => true]);
        $counter = app(CountingStageASnapshot::class);
        app()->bind(StageASnapshot::class, fn () => $counter);
        $after = $this->read($document);
        self::assertSame(1, $counter->projections);
        self::assertArrayHasKey('brief', $after);
        unset($after['brief']);
        $stable = static fn (array $payload): string => preg_replace(
            '/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:[+-]\d{2}:\d{2}|Z)/',
            '<instant>', json_encode($payload, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        self::assertSame($stable($before), $stable($after));
    }

    public function test_brief_uses_the_existing_document_policy_and_workspace_boundary(): void
    {
        config(['intelligence_v2.b2.enabled' => true]);
        $document = $this->rich();
        $this->checkpoint($document, 'completed', ['status' => 'verified',
            'claims' => [['text' => 'Private admitted narrative', 'cites' => []]]]);
        self::assertSame('verified', $this->read($document)['brief']['status']);

        $intruder = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($intruder);
        $response = $this->actingAs($intruder->fresh())
            ->getJson("/api/documents/{$document->id}/intelligence")->assertForbidden();
        self::assertStringNotContainsString('Private admitted narrative', $response->getContent());
        self::assertStringNotContainsString('Total financing', $response->getContent());
    }
}

class CountingStageASnapshot extends StageASnapshot
{
    public int $projections = 0;

    protected function project(Document $document, \DateTimeImmutable $asOf): array
    {
        $this->projections++;

        return parent::project($document, $asOf);
    }
}
