<?php

namespace Tests\Feature;

use App\Jobs\LocateImageTextJob;
use App\Jobs\LocatePdfTextJob;
use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Models\ProcessingJob;
use App\Models\User;
use App\Services\Intelligence\ImageWordBoxStore;
use App\Services\Ocr\PdfPageRenderer;
use App\Services\Ocr\PdfTextLocator;
use App\Services\Ocr\TesseractBinary;
use App\Services\Ocr\WordBoxRunner;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakePdfPageRenderer;
use Tests\Support\FakeWordBoxRunner;
use Tests\TestCase;

/**
 * Word-box location for scanned PDF pages. pdftoppm and tesseract are both faked;
 * nothing here needs poppler or a tesseract binary.
 */
class PdfTextLocationTest extends TestCase
{
    use RefreshDatabase;

    private const QUOTE = 'Total amount payable';

    private const TEXT = "Cover page text\fTotal amount payable is due\fThird page";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        config(['ocr.pdf_location.enabled' => true, 'ocr.pdf_location.max_pages' => 10,
            'ocr.image_location.min_word_confidence' => 60, 'ocr.image_location.min_matched_words' => 2]);
        $this->app->instance(TesseractBinary::class, new class extends TesseractBinary
        {
            public function available(): bool
            {
                return true;
            }
        });
    }

    /** @return array{0:User,1:Document,2:string} */
    private function fixture(int $citedPage = 2): array
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();
        Storage::disk('documents')->put('pdfs/scan.pdf', '%PDF-1.4 fixture');

        $document = Document::create(['workspace_id' => $user->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => 'Scan.pdf', 'type' => 'PDF', 'status' => 'Ready',
            'classification' => 'Internal', 'size_kb' => 1, 'year' => 2026, 'pages' => 3,
            'file_hash' => hash('sha256', 'pdf-fixture'), 'file_path' => 'pdfs/scan.pdf',
            'extracted_text' => self::TEXT, 'progress' => 100]);

        $sourceId = 'kpi:'.(string) Str::uuid();
        $start = mb_strpos(self::TEXT, self::QUOTE);
        DocumentEvidence::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'pipeline_key' => 'legacy',
            'identity' => hash('sha256', self::QUOTE), 'kind' => 'metric', 'source_id' => $sourceId,
            'data' => ['value' => '1', 'quote' => self::QUOTE],
            'sources' => [['quote' => self::QUOTE, 'page' => $citedPage, 'start_offset' => $start,
                'end_offset' => $start + mb_strlen(self::QUOTE), 'span_id' => 'fixture']]]);

        return [$user, $document, $sourceId];
    }

    private function run(Document $document, array $pages, ?FakePdfPageRenderer $renderer = null, ?WordBoxRunner $runner = null): FakePdfPageRenderer
    {
        $renderer ??= new FakePdfPageRenderer;
        $this->app->instance(PdfPageRenderer::class, $renderer);
        $this->app->instance(WordBoxRunner::class, $runner ?? new FakeWordBoxRunner(FakeWordBoxRunner::line(['Total', 'amount', 'payable', 'is', 'due'])));
        (new LocatePdfTextJob($document->id, null, $pages))->handle(app(PdfTextLocator::class), app(PipelineStageRecorder::class));

        return $renderer;
    }

    private function anchors(Document $document)
    {
        return DB::table('source_anchors as a')->join('source_provenance_sets as s', 's.id', '=', 'a.set_id')
            ->where('a.document_id', $document->id)->where('s.pipeline_key', ImageWordBoxStore::PIPELINE_KEY)
            ->orderBy('a.source_key')->get();
    }

    private function stage(Document $document): ProcessingJob
    {
        return ProcessingJob::where('document_id', $document->id)->where('stage', LocateImageTextJob::STAGE)->sole();
    }

    public function test_a_scanned_page_is_located_and_its_citation_returns_normalised_regions(): void
    {
        [$user, $document, $sourceId] = $this->fixture();
        $renderer = $this->run($document, [2]);

        $anchors = $this->anchors($document);
        self::assertCount(5, $anchors);
        $geometry = json_decode($anchors[0]->geometry, true);
        self::assertSame([2, 'ocr_region', 'ocr'], [(int) $anchors[0]->page, $anchors[0]->kind, $anchors[0]->modality]);
        self::assertSame(['image_pixels_top_left', 600, 800, 0], [$geometry['system'], $geometry['width'], $geometry['height'], $geometry['rotation']]);
        self::assertNull($anchors[0]->start_offset, 'a second reading is never an offset into the stored text');
        self::assertSame('completed', $this->stage($document)->status);
        self::assertSame([2], $this->stage($document)->output['located_pages']);
        self::assertSame((int) config('ocr.pdf_location.dpi'), $renderer->lastDpi);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/documents/'.$document->id.'/sources/'.$sourceId)->assertOk()
            ->assertJsonPath('data.textLocated', true)->assertJsonPath('data.source.page', 2)
            ->assertJsonPath('data.matchState', 'EXACT');
        $region = $response->json('data.regions.0');
        self::assertCount(1, $response->json('data.regions'));
        // "Total amount payable" spans x 10..200 of 600 and y 10..30 of 800 on the rendered page.
        self::assertEqualsWithDelta(10 / 600, $region['x'], 0.000002);
        self::assertEqualsWithDelta(190 / 600, $region['width'], 0.000002);
        self::assertEqualsWithDelta(10 / 800, $region['y'], 0.000002);
        self::assertEqualsWithDelta(20 / 800, $region['height'], 0.000002);
    }

    public function test_extraction_analysis_and_credits_are_untouched(): void
    {
        [, $document] = $this->fixture();
        $this->run($document, [2]);

        $document->refresh();
        self::assertSame(self::TEXT, $document->extracted_text);
        self::assertSame('Ready', $document->status);
        self::assertSame(0, DB::table('credit_ledger')->count());
        self::assertSame(0, DB::table('billing_operations')->count());
        self::assertSame(0, DB::table('ocr_results')->count());
        self::assertSame(0, DB::table('document_ai_runs')->count());
    }

    public function test_a_citation_on_a_page_without_boxes_is_not_located(): void
    {
        [$user, $document, $sourceId] = $this->fixture(citedPage: 1);
        $this->run($document, [2]); // Only page 2 was read by OCR; page 1 has a text layer.

        Sanctum::actingAs($user);
        $this->getJson('/api/documents/'.$document->id.'/sources/'.$sourceId)->assertOk()
            ->assertJsonPath('data.textLocated', false)->assertJsonPath('data.regions', []);
    }

    public function test_a_quote_the_boxes_do_not_contain_is_not_located(): void
    {
        [$user, $document, $sourceId] = $this->fixture();
        $this->run($document, [2], runner: new FakeWordBoxRunner(FakeWordBoxRunner::line(['Something', 'entirely', 'different'])));

        self::assertCount(3, $this->anchors($document));
        Sanctum::actingAs($user);
        $this->getJson('/api/documents/'.$document->id.'/sources/'.$sourceId)->assertOk()
            ->assertJsonPath('data.textLocated', false)->assertJsonPath('data.regions', [])
            ->assertJsonPath('data.matchState', 'EXACT');
    }

    public function test_a_page_with_no_confident_words_stores_nothing_and_still_completes(): void
    {
        [, $document] = $this->fixture();
        $this->run($document, [2], runner: new FakeWordBoxRunner(FakeWordBoxRunner::line(['Total', 'amount'], confidence: 0.3)));

        self::assertCount(0, $this->anchors($document));
        self::assertSame('completed', $this->stage($document)->status);
        self::assertSame('no_confident_words', $this->stage($document)->output['reason']);
        self::assertFalse($this->stage($document)->output['located']);
    }

    public function test_the_kill_switch_stops_the_stage_before_any_work(): void
    {
        config(['ocr.pdf_location.enabled' => false]);
        [, $document] = $this->fixture();
        $renderer = $this->run($document, [2]);

        self::assertSame([], $renderer->pages);
        self::assertCount(0, $this->anchors($document));
        self::assertSame(0, ProcessingJob::where('document_id', $document->id)->where('stage', LocateImageTextJob::STAGE)->count());
        self::assertSame('disabled', app(PdfTextLocator::class)->locate($document, [2])->reason);
    }

    public function test_only_the_configured_number_of_pages_is_located_and_the_rest_are_reported(): void
    {
        config(['ocr.pdf_location.max_pages' => 2]);
        [, $document] = $this->fixture();
        $renderer = $this->run($document, [4, 1, 3, 2, 3]);

        self::assertSame([1, 2], $renderer->pages, 'lowest page numbers first, duplicates ignored');
        $output = $this->stage($document)->output;
        self::assertSame([1, 2], $output['located_pages']);
        self::assertSame(2, $output['pages_skipped']);
        self::assertSame(2, $output['pages_attempted']);
    }

    public function test_a_render_failure_never_fails_the_document_and_leaves_no_temp_files(): void
    {
        [, $document] = $this->fixture();
        $renderer = $this->run($document, [1, 2], new FakePdfPageRenderer(failPages: [1, 2]));

        self::assertCount(0, $this->anchors($document));
        $output = $this->stage($document)->output;
        self::assertSame(['completed', 'ocr_failed', 2], [$this->stage($document)->status, $output['reason'], $output['pages_failed']]);
        $document->refresh();
        self::assertSame(['Ready', self::TEXT], [$document->status, $document->extracted_text]);
        foreach ($renderer->paths as $path) {
            self::assertFileDoesNotExist($path);
        }
    }

    public function test_a_tesseract_failure_is_contained_and_rendered_pages_are_cleaned_up(): void
    {
        [, $document] = $this->fixture();
        $renderer = $this->run($document, [2], runner: FakeWordBoxRunner::failing(new \RuntimeException('Word-box OCR timed out after 45s.')));

        self::assertCount(0, $this->anchors($document));
        self::assertSame('completed', $this->stage($document)->status);
        self::assertSame('Ready', $document->fresh()->status);
        self::assertCount(1, $renderer->paths);
        self::assertFileDoesNotExist($renderer->paths[0]);
    }

    public function test_one_failed_page_does_not_stop_the_others(): void
    {
        [, $document] = $this->fixture();
        $this->run($document, [1, 2], new FakePdfPageRenderer(failPages: [1]));

        $output = $this->stage($document)->output;
        self::assertSame([2], $output['located_pages']);
        self::assertSame(1, $output['pages_failed']);
        self::assertSame([2], $this->anchors($document)->pluck('page')->map(fn ($p) => (int) $p)->unique()->values()->all());
    }

    public function test_a_missing_tesseract_binary_is_recorded_as_skipped_not_failed(): void
    {
        $this->app->instance(TesseractBinary::class, new class extends TesseractBinary
        {
            public function available(): bool
            {
                return false;
            }
        });
        [, $document] = $this->fixture();
        $renderer = $this->run($document, [2]);

        self::assertSame([], $renderer->pages);
        self::assertSame('skipped', $this->stage($document)->status);
    }

    public function test_boxes_stop_backing_a_highlight_once_the_extracted_text_changes(): void
    {
        [$user, $document, $sourceId] = $this->fixture();
        $this->run($document, [2]);
        $document->forceFill(['extracted_text' => self::TEXT.' revised'])->save();

        Sanctum::actingAs($user);
        $this->getJson('/api/documents/'.$document->id.'/sources/'.$sourceId)->assertOk()
            ->assertJsonPath('data.textLocated', false)->assertJsonPath('data.regions', []);
    }
}
