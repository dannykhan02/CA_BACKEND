<?php

namespace App\Console\Commands;

use App\Http\Resources\DocumentIntelligenceResource;
use App\Http\Resources\KpiResource;
use App\Jobs\GenerateInsightsJob;
use App\Models\AiPrompt;
use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\DocumentEvidence;
use App\Models\DocumentKpi;
use App\Models\User;
use App\Services\AI\Incremental\EvidenceGrounding;
use App\Services\AI\LocalEvaluationCapture;
use App\Services\AI\PromptManager;
use App\Services\AnthropicClient;
use App\Services\Documents\DocumentStorageService;
use App\Services\Documents\SupportedDocumentTypes;
use App\Services\DocumentTextExtractor;
use App\Services\Extraction\SpreadsheetTextExtractor;
use App\Services\Extraction\StructuredExtractionStore;
use App\Services\Intelligence\DocumentAnalysisComposer;
use App\Services\Intelligence\NormalRouteProvenance;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceService;
use Database\Seeders\DocumentInsightsPromptSeederV10;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Restricted single-document evaluation; no queue worker or other model stage is started. */
class EvaluateLocalDocument extends Command
{
    protected $signature = 'docintel:evaluate-local
        {file : Local PDF, DOCX or XLSX file}
        {--live : Make one authorized Anthropic inference request}
        {--confirm-paid-call : Explicit authorization for this paid run}
        {--response= : Replay a captured provider response JSON offline}
        {--model= : Approved extraction model; defaults to configured extraction model}
        {--prompt=10 : Prompt version for this evaluation only}
        {--max-requests=1 : Per-run HTTP request limit}
        {--max-usd=0.01 : Per-run worst-case spending limit in USD}';

    protected $description = 'Run one local document through V10 and compare evidence resolution off/on';

    public function handle(): int
    {
        try {
            $this->preflight();
            $path = realpath((string) $this->argument('file'));
            if ($path === false || ! is_file($path) || ! is_readable($path)) {
                throw new RuntimeException('The local document is unreadable.');
            }
            $live = (bool) $this->option('live');
            $responsePath = $this->option('response');
            if ($live === ($responsePath !== null)) {
                throw new RuntimeException('Select exactly one of --live or --response.');
            }
            if ($live && (! $this->option('confirm-paid-call')
                || strlen(trim((string) config('services.anthropic.api_key'))) < 10)) {
                throw new RuntimeException('Live inference needs --confirm-paid-call and ANTHROPIC_API_KEY.');
            }
            if (! $live && $this->option('confirm-paid-call')) {
                throw new RuntimeException('Do not use --confirm-paid-call for offline replay.');
            }
            $model = trim((string) $this->option('model'));
            if ($model === '') {
                $model = (string) config('services.anthropic.extraction_model');
            }
            if (! in_array($model, config('document_intelligence.approved_models', []), true)
                || ! isset(config('document_intelligence.pricing', [])[$model])) {
                throw new RuntimeException('Select an approved model with configured pricing.');
            }
            if ((string) $this->option('prompt') !== '10') {
                throw new RuntimeException('This evaluation currently supports prompt version 10.');
            }
            $maxRequests = filter_var($this->option('max-requests'), FILTER_VALIDATE_INT);
            $maxUsd = filter_var($this->option('max-usd'), FILTER_VALIDATE_FLOAT);
            if ($maxRequests === false || $maxRequests < 1 || $maxRequests > 4
                || $maxUsd === false || ! is_finite($maxUsd) || $maxUsd <= 0 || $maxUsd > 1) {
                throw new RuntimeException('Limits require 1–4 requests and a positive USD cap no greater than 1.');
            }
            $type = app(SupportedDocumentTypes::class)->typeForExtension(pathinfo($path, PATHINFO_EXTENSION));
            if (! in_array($type, ['PDF', 'DOCX', 'XLSX'], true) || ! app(SupportedDocumentTypes::class)->isEnabled($type)) {
                throw new RuntimeException('The local evaluator supports enabled PDF, DOCX and XLSX sources with native text.');
            }
            $replay = null;
            $replayStatus = 200;
            if (! $live) {
                $realResponse = realpath((string) $responsePath);
                $restrictedRoot = realpath(storage_path('framework/testing/docintel-local-evaluation'));
                $legacyRoot = realpath(storage_path('framework/testing/docintel-v10-evaluation'));
                if ($realResponse === false || ! is_file($realResponse)
                    || ! (str_starts_with($realResponse, ($restrictedRoot ?: '/nonexistent').'/')
                        || str_starts_with($realResponse, ($legacyRoot ?: '/nonexistent').'/'))) {
                    throw new RuntimeException('Replay response must be a restricted local evaluation capture.');
                }
                $replay = file_get_contents($realResponse);
                if (! is_string($replay) || ! is_array(json_decode($replay, true))) {
                    throw new RuntimeException('Replay response is not a JSON object.');
                }
                $sibling = dirname($realResponse).'/report.json';
                if (basename($realResponse) === 'response-1.json' && is_file($sibling)) {
                    $prior = json_decode((string) file_get_contents($sibling), true);
                    $status = $prior['attempts'][0]['http_status'] ?? null;
                    if (is_int($status) && $status >= 100 && $status <= 599) {
                        $replayStatus = $status;
                    }
                }
                if (($decoded = json_decode($replay, true)) && isset($decoded['model'])
                    && $decoded['model'] !== $model) {
                    throw new RuntimeException('Replay model does not match --model.');
                }
            }

            // Settings are scoped to this CLI process. No active prompt row is changed.
            config(['cache.default' => 'array', 'queue.default' => 'sync',
                'services.anthropic.extraction_model' => $model,
                'document_intelligence.provider_gate.driver' => 'memory',
                'document_intelligence.revision_v1' => false,
                'document_intelligence.budget_max_usd' => $maxUsd,
                'document_intelligence.provider_call_max_usd' => $maxUsd,
                'kpi_identity.semantic_matching' => false,
                'intelligence_v2.enabled' => false,
                'filesystems.disks.documents.driver' => 'local',
                'filesystems.disks.documents.root' => storage_path('app/docintel-local-documents')]);
            Queue::fake(); // No embeddings, OCR, synthesis, or other provider calls.
            $this->callSilent('db:seed', ['--class' => DocumentInsightsPromptSeederV10::class, '--force' => true]);
            $prompt = AiPrompt::where('name', 'document_insights')->where('version', 10)->sole();
            app()->instance(PromptManager::class, new class($prompt) extends PromptManager
            {
                public function __construct(private AiPrompt $selected) {}

                public function resolve(string $name): AiPrompt
                {
                    return $name === 'document_insights' ? $this->selected : parent::resolve($name);
                }
            });

            $extractor = app(DocumentTextExtractor::class);
            $structured = match ($type) {
                'PDF' => (function () use ($extractor, $path) {
                    $extractor->extractPdfText($path);
                    return $extractor->pdfStructuredResult();
                })(),
                'DOCX' => $extractor->extractDocxStructured($path),
                'XLSX' => app(SpreadsheetTextExtractor::class)->extractStructured($path),
            };
            $text = (string) $structured?->text;
            if (trim($text) === '') {
                throw new RuntimeException('No native text was extracted; OCR is outside this one-request evaluation.');
            }
            $pages = $type === 'PDF' ? $extractor->countPdfPages($path) : 0;
            $document = $this->makeDocument($path, $type, $text, $pages, $structured, $model, $maxUsd);
            if (($document->ai_pipeline['normal_span_grounding'] ?? false) !== true) {
                throw new RuntimeException('Document exceeds the lossless normal-route span prompt limit; no provider request was made.');
            }
            $spans = app(EvidenceGrounding::class)->spans($document);
            $sourceText = app(NormalRouteProvenance::class)->promptText($document, $text);
            $rendered = app(PromptManager::class)->render($prompt, [
                '{{document_name}}' => $document->name,
                '{{document_text}}' => mb_substr($sourceText, 0, config('document_processing.max_extraction_chars')),
                '{{document_classification}}' => $document->classification,
            ])."\n\nFor this response, select at most "
                .(int) config('document_processing.insights_max_v10_observations')
                .' independently cited high-value observations and '
                .(int) config('document_processing.insights_max_observations')
                .' insights. Prioritize comparable measurements and required operands; keep the V10 JSON schema complete.';
            $dir = storage_path('framework/testing/docintel-local-evaluation/run-'.date('Ymd-His').'-'.bin2hex(random_bytes(5)));
            $capture = new LocalEvaluationCapture($dir, $model, hash('sha256', $rendered), $maxRequests, $maxUsd);
            Http::globalMiddleware($capture->middleware());
            if ($live) {
                Http::preventStrayRequests(false);
            } else {
                Http::preventStrayRequests();
                Http::fake(['api.anthropic.com/v1/messages' => Http::response($replay, $replayStatus,
                    ['Content-Type' => 'application/json'])]);
            }
            $started = hrtime(true);
            $report = ['source_file' => basename($path), 'source_sha256' => hash_file('sha256', $path),
                'document_id' => $document->id,
                'extraction_id' => DB::table('structured_extractions')->where('document_id', $document->id)
                    ->where('active', true)->value('id'),
                'extraction_sha256' => hash('sha256', $text),
                'source_span_count' => $spans->count(), 'provider' => 'anthropic', 'model' => $model,
                'prompt_version' => 10, 'prompt_sha256' => hash('sha256', $prompt->template),
                'rendered_prompt_sha256' => hash('sha256', $rendered),
                'mode' => $live ? 'live' : 'replay', 'max_requests' => $maxRequests, 'max_usd' => $maxUsd,
                'factual_correctness' => 'requires independent review'];
            try {
                $result = app(AnthropicClient::class)->extractDocumentInsights($text, $document->name,
                    $document, singleResponse: true);
                if (($result['prompt_version'] ?? null) !== 10) {
                    throw new RuntimeException('The selected V10 prompt was not used.');
                }
                $report['model_candidates'] = $result['kpis'] ?? [];
                $report['modes']['off'] = $this->process($document, $result, false);
                $report['modes']['on'] = $this->process($this->makeDocument($path, $type, $text, $pages,
                    $structured, $model, $maxUsd), $result, true);
                $report['comparison'] = $this->comparison($report['modes']);
                $report['outcome'] = 'success';
            } catch (\Throwable $error) {
                $report['outcome'] = 'failed';
                $report['failure_type'] = $error::class;
                // Provider exception text may contain sensitive content; never persist it.
                throw $error;
            } finally {
                $report['request_count'] = $capture->requests;
                $report['paid_request_count'] = $live ? $capture->requests : 0;
                $report['maximum_reserved_usd'] = $capture->reservedUsd;
                $report['attempts'] = $capture->attempts;
                $report['recorded_response_estimated_cost_usd'] = array_sum(array_column($capture->attempts,
                    'estimated_cost_usd'));
                $report['estimated_api_cost_usd'] = $live ? $report['recorded_response_estimated_cost_usd'] : 0;
                $report['duration_ms'] = (int) ((hrtime(true) - $started) / 1e6);
                $capture->write('report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                $capture->write('report.md', $this->markdown($report));
                $this->info('Restricted evaluation report: '.$dir.'/report.md');
            }

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error('Evaluation stopped: '.$error::class.'. See restricted report if a run began.');

            return self::FAILURE;
        }
    }

    private function preflight(): void
    {
        if (! app()->environment(['local', 'testing']) || config('database.default') !== 'pgsql') {
            throw new RuntimeException('Evaluation requires a local PostgreSQL application.');
        }
        $db = config('database.connections.pgsql');
        if (($db['host'] ?? null) !== '127.0.0.1' || ! empty($db['url'])
            || ! preg_match('/\Adocintel_[a-z0-9_]*eval\z/', (string) ($db['database'] ?? ''))
            || (string) ($db['sslmode'] ?? 'disable') === 'require') {
            throw new RuntimeException('Evaluation requires a named disposable loopback database.');
        }
        $dataDir = realpath((string) DB::selectOne("select current_setting('data_directory') as directory")->directory);
        $testRoot = realpath(storage_path('framework/testing'));
        if ($dataDir === false || ! ($testRoot && str_starts_with($dataDir, $testRoot.'/'))
            && ! str_starts_with($dataDir, '/tmp/docintel-')) {
            throw new RuntimeException('Database cluster is not an approved disposable local cluster.');
        }
    }

    private function makeDocument(string $path, string $type, string $text, int $pages,
        ?\App\Services\Extraction\StructuredDocument $structured, string $model, float $budget): Document
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $workspace = $user->fresh()->currentWorkspace;
        $workspace->credits()->update(['documents_remaining' => 5]);
        $upload = new UploadedFile($path, basename($path), null, null, true);
        $stored = app(DocumentStorageService::class)->store($upload, $workspace->id);
        $storedPath = Storage::disk('documents')->path($stored);
        if (! chmod(storage_path('app/docintel-local-documents'), 0700)
            || ! chmod(dirname($storedPath), 0700) || ! chmod($storedPath, 0600)) {
            throw new RuntimeException('Could not restrict the local document copy.');
        }
        $document = Document::create(['workspace_id' => $workspace->id, 'uploaded_by' => $user->id,
            'name' => basename($path), 'type' => $type, 'status' => 'Processing',
            'classification' => 'Internal', 'size_kb' => (int) ceil(filesize($path) / 1024),
            'year' => (int) now()->year, 'pages' => $pages, 'file_hash' => hash_file('sha256', $path),
            'file_path' => $stored, 'extracted_text' => $text, 'insights' => []]);
        if ($structured !== null) {
            app(StructuredExtractionStore::class)->publish($document, $structured);
        }
        $document = $document->fresh();
        $document->forceFill(['ai_pipeline' => ['route' => 'normal',
            'key' => 'normal:local-evaluation:'.$document->id,
            'normal_span_grounding' => app(NormalRouteProvenance::class)->eligible($document, $text),
            'text_hash' => hash('sha256', $text), 'model' => $model,
            'budget_usd' => $budget, 'tokens' => strlen($text), 'count_method' => 'byte_upper_bound']])->save();

        return $document->fresh();
    }

    private function process(Document $document, array $result, bool $resolve): array
    {
        $started = hrtime(true);
        config(['document_intelligence.server_evidence_resolution' => $resolve]);
        $client = new class($result) extends AnthropicClient
        {
            public function __construct(private array $recorded) {}

            public function extractDocumentInsights(string $documentText, string $documentName, ?Document $document = null,
                bool $singleResponse = false): array
            {
                return $this->recorded;
            }
        };
        (new GenerateInsightsJob($document->id))->handle($client, app(PipelineStageRecorder::class));
        $document = $document->fresh();
        if ($document->status !== 'Ready') {
            throw new RuntimeException('The normal insights job did not complete.');
        }
        $request = Request::create('/api/documents/'.$document->id.'/intelligence');
        $api = (new DocumentIntelligenceResource($document))->resolve($request);
        $analysis = app(DocumentAnalysisComposer::class)->compose($document);
        $rows = DocumentKpi::where('document_id', $document->id)->orderBy('created_at')->orderBy('id')->get();
        $observations = [];
        $rejections = [];
        $eligible = 0;
        foreach ($rows as $row) {
            $sourceId = 'kpi:'.$row->id;
            $evidence = DocumentEvidence::where('document_id', $document->id)->where('source_id', $sourceId)->first();
            $request->attributes->set('kpi_evidence', collect([$sourceId => $evidence]));
            $request->attributes->set('document_source_revision', hash('sha256', (string) $document->extracted_text));
            $card = (new KpiResource($row))->resolve($request);
            $eligible += (int) $card['analyticalEligible'];
            foreach ($card['limitationReasons'] as $reason) {
                $rejections[$reason] = ($rejections[$reason] ?? 0) + 1;
            }
            $observations[] = ['source_id' => $sourceId, 'label' => $row->label,
                'value' => $row->value, 'unit' => $row->unit, 'period' => $row->period,
                'identity' => $row->identity_metadata, 'analytical_eligible' => $card['analyticalEligible'],
                'verification' => ['source' => $card['sourceVerification'],
                    'numeric' => $card['numericVerification'], 'unit' => $card['unitVerification']],
                'refusal_reasons' => $card['limitationReasons'],
                'evidence' => $evidence ? ['data' => $evidence->data, 'sources' => $evidence->sources] : null];
        }
        $provenCells = count(array_filter($observations, function ($item) use ($document) {
            $data = $item['evidence']['data'] ?? [];
            $start = $data['source_cell_start_offset'] ?? null;
            $end = $data['source_cell_end_offset'] ?? null;

            return is_int($start) && is_int($end) && $end > $start
                && mb_substr((string) $document->extracted_text, $start, $end - $start)
                    === ($data['source_cell_value'] ?? null);
        }));
        $exactQuotes = 0;
        $invalidQuotes = 0;
        $provenSubjects = 0;
        foreach ($observations as $item) {
            $data = $item['evidence']['data'] ?? [];
            if (in_array('subject', $data['source_fields_verified'] ?? [], true)) {
                $provenSubjects++;
            }
            foreach ($item['evidence']['sources'] ?? [] as $source) {
                $exact = is_int($source['start_offset'] ?? null)
                    && is_int($source['end_offset'] ?? null)
                    && $source['end_offset'] > $source['start_offset']
                    && mb_substr((string) $document->extracted_text, $source['start_offset'],
                        $source['end_offset'] - $source['start_offset']) === ($source['quote'] ?? null);
                $exact ? $exactQuotes++ : $invalidQuotes++;
            }
        }

        return ['document_id' => $document->id,
            'extraction_id' => DB::table('structured_extractions')->where('document_id', $document->id)
                ->where('active', true)->value('id'),
            'pipeline_duration_ms' => (int) ((hrtime(true) - $started) / 1e6),
            'model_observations' => count($result['kpis'] ?? []),
            'server_generated_observations' => max(0, $rows->count() - count($result['kpis'] ?? [])),
            'accepted_observations' => $eligible, 'rejected_observations' => $rows->count() - $eligible,
            'rejection_reasons' => $rejections, 'source_linked_observations' => count(array_filter($observations,
                fn ($item) => $item['evidence'] !== null)), 'proven_table_cells' => $provenCells,
            'exact_source_quotes' => $exactQuotes, 'invalid_source_quotes' => $invalidQuotes,
            'proven_subject_roles' => $provenSubjects,
            'observations' => $observations, 'financial_series' => array_values(array_filter(
                $analysis['visualAnalysis']['charts'], fn ($chart) => ($chart['basis'] ?? null) === 'time_series')),
            'charts' => $analysis['visualAnalysis']['charts'],
            'comparisons' => $analysis['analyticalComparisons'],
            'analysis_stats' => $analysis['stats'], 'composite_api' => $api,
            'api_evidence' => $api['evidence'],
            'api_evidence_mapping_count' => count((array) $api['evidence']),
            'usage' => DocumentAiRun::where('document_id', $document->id)->where('purpose', 'insights')
                ->get(['model', 'prompt_version', 'input_tokens', 'output_tokens', 'estimated_cost_usd', 'duration_ms'])->toArray()];
    }

    private function comparison(array $modes): array
    {
        $fields = ['model_observations', 'server_generated_observations', 'accepted_observations',
            'rejected_observations', 'source_linked_observations', 'proven_table_cells',
            'exact_source_quotes', 'invalid_source_quotes', 'proven_subject_roles',
            'api_evidence_mapping_count'];
        $comparison = [];
        foreach ($fields as $field) {
            $comparison[$field] = ['off' => $modes['off'][$field], 'on' => $modes['on'][$field]];
        }
        foreach (['financial_series', 'charts', 'comparisons'] as $field) {
            $comparison[$field] = ['off' => count($modes['off'][$field]), 'on' => count($modes['on'][$field])];
        }

        return $comparison;
    }

    private function markdown(array $report): string
    {
        $lines = ['# DocIntel local evaluation', '',
            'Outcome: **'.$report['outcome'].'**',
            'Source: `'.$report['source_file'].'` (`'.$report['source_sha256'].'`)',
            'Extraction: `'.$report['extraction_sha256'].'`; '.$report['source_span_count'].' source spans',
            'Provider/model: Anthropic / `'.$report['model'].'`; prompt V10',
            'Requests: '.$report['request_count'].' (paid: '.$report['paid_request_count']
                .'); maximum reserved USD: '.$report['maximum_reserved_usd']
                .'; estimated API cost USD: '.$report['estimated_api_cost_usd'],
            'Duration: '.$report['duration_ms'].' ms',
            'Verification decisions require independent factual review.', '',
            'Exact request and response, detailed observations, evidence, refusal reasons, charts, '
                .'comparisons and API mappings are in the restricted JSON files in this directory.', ''];
        if (isset($report['comparison'])) {
            $lines[] = '| Result | Resolution off | Resolution on |';
            $lines[] = '| --- | ---: | ---: |';
            foreach ($report['comparison'] as $field => $values) {
                $lines[] = '| '.str_replace('_', ' ', $field).' | '.$values['off'].' | '.$values['on'].' |';
            }
        }

        return implode("\n", $lines)."\n";
    }
}
