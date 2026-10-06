<?php

namespace Tests\Feature;

use App\Exceptions\AiProcessingException;
use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\DocumentChunk;
use App\Models\User;
use App\Services\AI\Incremental\EvidenceGrounding;
use App\Services\AI\Incremental\EvidenceSchema;
use App\Services\AI\Incremental\IncrementalPipeline;
use App\Services\AnthropicClient;
use App\Services\WorkspaceService;
use Database\Seeders\DocumentSummaryPromptSeederV3;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\TestCase;

/**
 * Diagnosability of a provider-rejected extraction call.
 *
 * Production saw every span-mode entities call fail in ~200ms with input_tokens 0, output_tokens 0,
 * result provider_error and failure_class deterministic, and nothing else: the Anthropic status,
 * error type, error message and request id were read only to classify and then dropped. These tests
 * pin the diagnostic down without weakening the classification it describes, and keep the provider's
 * own words from dragging secrets or source text into the log with them.
 */
class AnthropicProviderErrorDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_KEY = 'sk-ant-api03-DO-NOT-LOG-THIS-KEY';

    private const SOURCE_TEXT = "The Bank approved thirty-seven sovereign operations during the 2024 financial year.\n\n"
        ."Total commitments reached USD 10 in 2024, compared with USD 9 in 2023.\n\n"
        ."Private-sector financing increased substantially against a demanding external backdrop.\n\n";

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
        $this->seed(DocumentSummaryPromptSeederV3::class);
        config([
            'services.anthropic.api_key' => self::SECRET_KEY,
            'services.anthropic.extraction_model' => 'claude-haiku-4-5-20251001',
            'document_intelligence.large_tokens' => 100,
            'document_intelligence.chunk_max_tokens' => 100,
            'document_intelligence.chunk_overlap_tokens' => 5,
            'document_intelligence.minimum_split_chars' => 10,
            'document_intelligence.budget_base_usd' => 2,
        ]);
    }

    private function document(bool $spans): Document
    {
        config(['document_intelligence.evidence_spans' => $spans]);
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->fresh()->currentWorkspace->credits()->update(['documents_remaining' => 5]);
        $document = Document::create(['workspace_id' => $user->current_workspace_id, 'uploaded_by' => $user->id,
            'name' => 'Synthetic report.pdf', 'type' => 'PDF', 'status' => 'Processing', 'classification' => 'Internal',
            'size_kb' => 10, 'year' => 2026, 'pages' => 3, 'file_hash' => hash('sha256', 'diag'.$user->id),
            'extracted_text' => str_repeat(self::SOURCE_TEXT, 6)]);
        $document->forceFill(['ai_pipeline' => ['tokens' => 1000, 'route' => 'incremental']])->save();
        app(IncrementalPipeline::class)->start($document);

        return $document->refresh();
    }

    private function firstLeaf(Document $document): DocumentChunk
    {
        return DocumentChunk::where('document_id', $document->id)->where('stage', 'extraction')
            ->orderBy('start_offset')->firstOrFail();
    }

    /** An Anthropic 400 rejection, in the shape the provider actually returns one. */
    private function rejection(string $message, array $headers = ['request-id' => 'req_011CQxSpanReject']): \Closure
    {
        return fn () => Http::response(['type' => 'error',
            'error' => ['type' => 'invalid_request_error', 'message' => $message]], 400, $headers);
    }

    /** @return array<string,mixed> the context of the single provider-error diagnostic */
    private function captureProviderError(TestHandler $handler): array
    {
        $records = array_values(array_filter($handler->getRecords(),
            fn ($entry) => $entry['message'] === 'Anthropic provider error'));
        self::assertCount(1, $records, 'Exactly one provider-error diagnostic per rejected response.');

        return $records[0]['context'];
    }

    private function runExtraction(Document $document, DocumentChunk $chunk, \Closure $fake): TestHandler
    {
        Http::fake(['api.anthropic.com/*' => $fake]);
        $handler = new TestHandler;
        Log::swap(new Logger('test', [$handler]));
        try {
            app(AnthropicClient::class)->extractChunk($document, $chunk,
                substr((string) $document->extracted_text, (int) $chunk->start_offset,
                    (int) $chunk->end_offset - (int) $chunk->start_offset));
            self::fail('A rejected provider request must not return an extraction result.');
        } catch (AiProcessingException $e) {
            self::assertSame('deterministic', $e->classification,
                'Classification of an unclassified 4xx must not move.');
        }

        return $handler;
    }

    // ---------------------------------------------------------------- TASK 4: local reproduction

    /**
     * The span request schema, checked against the JSON Schema subset Anthropic's structured
     * outputs accept, with no live call. Array constraints beyond minItems 0 or 1 are rejected
     * with a 400 before inference, which is exactly the observed failure shape.
     */
    public function test_span_schema_carries_an_unsupported_array_constraint(): void
    {
        $legacy = EvidenceSchema::extraction(EvidenceGrounding::LEGACY)['properties']['records']['items']['properties'];
        $spans = EvidenceSchema::extraction(EvidenceGrounding::SPANS)['properties']['records']['items']['properties'];

        self::assertArrayNotHasKey('evidence_ids', $legacy, 'evidence_ids exists only in span mode.');
        self::assertArrayHasKey('quote', $legacy);
        self::assertArrayNotHasKey('quote', $spans);

        // minItems is accepted only for the values 0 and 1; maxItems is not accepted at all.
        self::assertSame(1, $spans['evidence_ids']['minItems']);
        self::assertSame(EvidenceSchema::maxEvidenceIds(), $spans['evidence_ids']['maxItems']);
        self::assertSame([], $this->unsupportedKeywords($legacy),
            'The legacy schema uses only supported keywords, which is why it still reaches inference.');
        self::assertSame(['records.items.evidence_ids.maxItems'], $this->unsupportedKeywords($spans),
            'maxItems is the only keyword span mode adds that the provider subset does not accept.');
    }

    /**
     * A synthetic span-mode request over harmless fixture text, proving the rejection is a property
     * of the request the client builds and not of any particular document's content.
     */
    public function test_synthetic_span_request_is_rejected_before_inference(): void
    {
        $document = $this->document(spans: true);
        self::assertSame(EvidenceGrounding::SPANS, app(EvidenceGrounding::class)->mode($document));
        $chunk = $this->firstLeaf($document);

        $sent = null;
        Http::fake(['api.anthropic.com/*' => function ($request) use (&$sent) {
            $sent = $request->data();

            return Http::response(['type' => 'error', 'error' => ['type' => 'invalid_request_error',
                'message' => 'output_config.format.schema: maxItems is not supported']], 400,
                ['request-id' => 'req_011CQxSynthetic']);
        }]);
        $handler = new TestHandler;
        Log::swap(new Logger('test', [$handler]));
        try {
            app(AnthropicClient::class)->extractChunk($document, $chunk, substr((string) $document->extracted_text,
                (int) $chunk->start_offset, (int) $chunk->end_offset - (int) $chunk->start_offset));
            self::fail('Rejected request returned a result.');
        } catch (AiProcessingException) {
        }

        // The request that gets rejected is the span-mode one: labeled spans in, a maxItems schema out.
        $schema = $sent['output_config']['format']['schema']['properties']['records']['items']['properties'];
        self::assertArrayHasKey('maxItems', $schema['evidence_ids']);
        self::assertStringContainsString('evidence_spans', json_encode($sent['messages']));
        self::assertMatchesRegularExpression('/\[E\d+\]/', json_encode($sent['messages']),
            'Span mode sends the slice as labeled spans.');

        $context = $this->captureProviderError($handler);
        self::assertSame(400, $context['status']);
        self::assertSame('invalid_request_error', $context['error_type']);
        self::assertStringContainsString('maxItems is not supported', $context['error_message']);
        self::assertSame('entities', $context['purpose']);
        self::assertSame($chunk->id, $context['chunk_id']);
    }

    // ------------------------------------------------------------------- TASK 5: the diagnostic

    public function test_span_mode_provider_error_reports_every_diagnostic_field(): void
    {
        $document = $this->document(spans: true);
        $chunk = $this->firstLeaf($document);
        $handler = $this->runExtraction($document, $chunk,
            $this->rejection('output_config.format.schema: maxItems is not supported'));

        $context = $this->captureProviderError($handler);
        self::assertSame(400, $context['status']);
        self::assertSame('invalid_request_error', $context['error_type']);
        self::assertSame('output_config.format.schema: maxItems is not supported', $context['error_message']);
        self::assertSame('req_011CQxSpanReject', $context['request_id']);
        self::assertSame('entities', $context['purpose']);
        self::assertSame('claude-haiku-4-5-20251001', $context['model']);
        self::assertSame($document->id, $context['document_id']);
        self::assertSame($chunk->id, $context['chunk_id']);
        self::assertSame('deterministic', $context['failure_class']);
        self::assertIsInt($context['duration_ms']);
        self::assertGreaterThanOrEqual(0, $context['duration_ms']);
    }

    public function test_request_id_is_persisted_on_the_rejected_run_row(): void
    {
        $document = $this->document(spans: true);
        $this->runExtraction($document, $this->firstLeaf($document), $this->rejection('rejected'));

        $run = DocumentAiRun::where('document_id', $document->id)->where('purpose', 'entities')
            ->orderByDesc('created_at')->firstOrFail();
        self::assertSame('req_011CQxSpanReject', $run->provider_request_id,
            'A rejected call is only findable in the provider console by its request id.');
    }

    /** A rejection with no request id must still produce the diagnostic, with a null id. */
    public function test_missing_request_id_degrades_to_null(): void
    {
        $document = $this->document(spans: true);
        $handler = $this->runExtraction($document, $this->firstLeaf($document),
            $this->rejection('rejected', headers: []));

        self::assertNull($this->captureProviderError($handler)['request_id']);
        $run = DocumentAiRun::where('document_id', $document->id)->where('purpose', 'entities')
            ->orderByDesc('created_at')->firstOrFail();
        self::assertNull($run->provider_request_id);
        self::assertSame('deterministic', $run->failure_class);
    }

    public function test_deterministic_classification_and_billing_shape_are_unchanged(): void
    {
        $document = $this->document(spans: true);
        $chunk = $this->firstLeaf($document);
        // runExtraction already asserts the thrown classification is still 'deterministic'.
        $this->runExtraction($document, $chunk, $this->rejection('rejected'));

        $run = DocumentAiRun::where('document_id', $document->id)->where('purpose', 'entities')
            ->orderByDesc('created_at')->firstOrFail();
        self::assertSame('provider_error', $run->status);
        self::assertSame('deterministic', $run->failure_class);
        self::assertSame(0, (int) $run->input_tokens);
        self::assertSame(0, (int) $run->output_tokens);
        self::assertSame(0.0, (float) $run->estimated_cost_usd);
    }

    public function test_legacy_provider_errors_behave_identically_and_are_diagnosable(): void
    {
        $document = $this->document(spans: false);
        self::assertSame(EvidenceGrounding::LEGACY, app(EvidenceGrounding::class)->mode($document));
        $chunk = $this->firstLeaf($document);
        $handler = $this->runExtraction($document, $chunk, $this->rejection('temporarily unavailable'));

        $context = $this->captureProviderError($handler);
        self::assertSame(400, $context['status']);
        self::assertSame('invalid_request_error', $context['error_type']);
        self::assertSame('deterministic', $context['failure_class']);
        self::assertSame($chunk->id, $context['chunk_id']);

        $run = DocumentAiRun::where('document_id', $document->id)->where('purpose', 'entities')
            ->orderByDesc('created_at')->firstOrFail();
        self::assertSame('provider_error', $run->status);
        self::assertSame('deterministic', $run->failure_class);
        self::assertSame(0, (int) $run->input_tokens);
    }

    /** A 401 still classifies as authentication, and the diagnostic says so rather than guessing. */
    public function test_other_statuses_keep_their_existing_classification(): void
    {
        $document = $this->document(spans: true);
        $chunk = $this->firstLeaf($document);
        Http::fake(['api.anthropic.com/*' => fn () => Http::response(['error' => ['type' => 'authentication_error',
            'message' => 'invalid x-api-key']], 401, ['request-id' => 'req_auth'])]);
        $handler = new TestHandler;
        Log::swap(new Logger('test', [$handler]));
        try {
            app(AnthropicClient::class)->extractChunk($document, $chunk, 'text');
            self::fail('A 401 must not return a result.');
        } catch (AiProcessingException $e) {
            self::assertSame('authentication', $e->classification);
        }
        self::assertSame('authentication', $this->captureProviderError($handler)['failure_class']);
    }

    // ------------------------------------------------------------------ TASK 5: what must not leak

    public function test_no_secret_prompt_or_source_text_reaches_the_log(): void
    {
        $document = $this->document(spans: true);
        $chunk = $this->firstLeaf($document);
        $handler = $this->runExtraction($document, $chunk, $this->rejection('rejected'));

        $logged = json_encode($handler->getRecords());
        self::assertStringNotContainsString(self::SECRET_KEY, $logged, 'The API key must never be logged.');
        self::assertStringNotContainsString('x-api-key', $logged, 'Request headers must never be logged.');
        self::assertStringNotContainsString('thirty-seven sovereign operations', $logged,
            'Source document text must never be logged.');
        self::assertStringNotContainsString('Extract grounded corporate-document evidence', $logged,
            'The system prompt must never be logged.');
        self::assertStringNotContainsString('evidence_spans', $logged, 'The request body must never be logged.');

        $context = $this->captureProviderError($handler);
        self::assertSame(['status', 'error_type', 'error_message', 'request_id', 'purpose', 'model',
            'document_id', 'chunk_id', 'failure_class', 'request_attempt', 'duration_ms'], array_keys($context),
            'The diagnostic is a fixed, reviewed field set; nothing else travels with it.');
    }

    public function test_provider_message_is_redacted_flattened_and_capped(): void
    {
        $document = $this->document(spans: true);
        $chunk = $this->firstLeaf($document);
        $handler = $this->runExtraction($document, $chunk, $this->rejection(
            "key \n\n ".self::SECRET_KEY." used\t".str_repeat('A', 600)));

        $message = $this->captureProviderError($handler)['error_message'];
        self::assertStringNotContainsString(self::SECRET_KEY, $message);
        self::assertStringContainsString('[redacted]', $message);
        self::assertStringNotContainsString("\n", $message, 'Whitespace is collapsed to one line.');
        self::assertStringEndsWith('...[truncated]', $message);
        self::assertLessThanOrEqual(420, mb_strlen($message));
    }

    /** A provider body that is not the documented error shape must degrade, never throw or leak. */
    public function test_unparseable_error_body_degrades_to_nulls(): void
    {
        $document = $this->document(spans: true);
        $chunk = $this->firstLeaf($document);
        $handler = $this->runExtraction($document, $chunk,
            fn () => Http::response('<html>502 Bad Gateway</html>', 400));

        $context = $this->captureProviderError($handler);
        self::assertSame(400, $context['status']);
        self::assertNull($context['error_type']);
        self::assertNull($context['error_message']);
        self::assertNull($context['request_id']);
        self::assertSame('deterministic', $context['failure_class']);
    }

    /**
     * Anthropic's documented structured-output JSON Schema subset. Anything outside it is a 400
     * before inference, so a schema keyword audit is a local substitute for a live rejection.
     *
     * @return list<string> dotted paths of keywords the provider does not accept
     */
    private function unsupportedKeywords(array $properties, string $prefix = 'records.items'): array
    {
        $supported = ['type', 'properties', 'required', 'additionalProperties', 'items', 'enum', 'const',
            'anyOf', 'allOf', '$ref', '$defs', 'definitions', 'default', 'description', 'format', 'title'];
        $found = [];
        foreach ($properties as $name => $definition) {
            if (! is_array($definition)) {
                continue;
            }
            foreach ($definition as $keyword => $value) {
                // minItems is the one array constraint accepted, and only for the values 0 and 1.
                if ($keyword === 'minItems' && in_array($value, [0, 1], true)) {
                    continue;
                }
                if (! in_array($keyword, $supported, true)) {
                    $found[] = "{$prefix}.{$name}.{$keyword}";
                }
            }
            if (is_array($definition['properties'] ?? null)) {
                $found = [...$found, ...$this->unsupportedKeywords($definition['properties'], "{$prefix}.{$name}")];
            }
        }

        return $found;
    }
}
