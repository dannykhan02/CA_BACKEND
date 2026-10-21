<?php

namespace Tests\Feature;

use App\Jobs\LocateImageTextJob;
use App\Models\Document;
use App\Models\DocumentIntelligenceRevision;
use App\Models\ProcessingJob;
use App\Models\User;
use App\Services\Intelligence\DocumentRevisionPublisher;
use App\Services\Intelligence\ImageWordBoxStore;
use App\Services\Ocr\ImageTextLocator;
use App\Services\Ocr\TesseractBinary;
use App\Services\Ocr\WordBox;
use App\Services\Ocr\WordBoxRunner;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\Support\FakeWordBoxRunner;
use Tests\TestCase;

class ImageTextLocationStageTest extends TestCase
{
    use RefreshDatabase;

    private const TEXT = 'Total amount payable';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        config(['ocr.image_location.enabled' => true,
            'ocr.image_location.min_word_confidence' => 60,
            'ocr.tesseract_binary' => '/usr/bin/tesseract']);
        // Every test here runs against the fake runner; the real binary is
        // exercised only by ImageTextLocatorRealBinaryTest.
        $this->app->instance(TesseractBinary::class, new class extends TesseractBinary
        {
            public function available(): bool
            {
                return true;
            }
        });
    }

    private function document(string $type = 'JPG'): Document
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();

        $image = imagecreatetruecolor(400, 200);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $local = tempnam(sys_get_temp_dir(), 'img_stage_');
        imagejpeg($image, $local);
        imagedestroy($image);
        Storage::disk('documents')->put('images/source.jpg', file_get_contents($local));
        @unlink($local);

        return Document::create(['workspace_id' => $user->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => 'Invoice.jpg', 'type' => $type,
            'status' => 'Ready', 'classification' => 'Internal', 'size_kb' => 1, 'year' => 2026,
            'file_hash' => hash('sha256', 'img-fixture'), 'file_path' => 'images/source.jpg',
            'extracted_text' => self::TEXT, 'progress' => 100]);
    }

    private function runner(WordBoxRunner $runner): void
    {
        $this->app->instance(WordBoxRunner::class, $runner);
    }

    private function anchors(Document $document)
    {
        return DB::table('source_anchors as a')
            ->join('source_provenance_sets as s', 's.id', '=', 'a.set_id')
            ->where('a.document_id', $document->id)
            ->where('s.pipeline_key', ImageWordBoxStore::PIPELINE_KEY)
            ->orderBy('a.source_key')->get();
    }

    private function stageRows(Document $document)
    {
        return ProcessingJob::where('document_id', $document->id)
            ->where('stage', LocateImageTextJob::STAGE)->get();
    }

    public function test_confident_words_are_stored_as_ocr_region_anchors_with_oriented_dimensions(): void
    {
        $document = $this->document();
        $this->runner(new FakeWordBoxRunner(FakeWordBoxRunner::line(['Total', 'amount', 'payable'])));

        (new LocateImageTextJob($document->id))->handle(app(ImageTextLocator::class),
            app(PipelineStageRecorder::class));

        $anchors = $this->anchors($document);
        self::assertCount(3, $anchors);
        foreach ($anchors as $anchor) {
            $geometry = json_decode($anchor->geometry, true);
            self::assertSame('ocr_region', $anchor->kind);
            self::assertSame('ocr', $anchor->modality);
            self::assertSame(1, (int) $anchor->page);
            self::assertSame('image_pixels_top_left', $geometry['system']);
            self::assertSame(400, $geometry['width']);
            self::assertSame(200, $geometry['height']);
            // 0 is a fact, not a placeholder: the image was oriented first.
            self::assertSame(0, $geometry['rotation']);
            self::assertSame(0.9, $geometry['confidence']);
            // Tesseract's reading is not an offset into the claude_vision text.
            self::assertNull($anchor->start_offset);
            self::assertNull($anchor->end_offset);
        }
        self::assertSame('Total', $anchors[0]->source_text);

        $stage = $this->stageRows($document);
        self::assertCount(1, $stage);
        self::assertSame('completed', $stage[0]->status);
        self::assertSame(3, $stage[0]->output['words_stored']);
    }

    public function test_extraction_and_analysis_are_untouched_by_the_location_stage(): void
    {
        $document = $this->document();
        $this->runner(new FakeWordBoxRunner(FakeWordBoxRunner::line(['Total', 'amount', 'payable'])));

        (new LocateImageTextJob($document->id))->handle(app(ImageTextLocator::class),
            app(PipelineStageRecorder::class));

        $document->refresh();
        self::assertSame(self::TEXT, $document->extracted_text);
        self::assertSame('Ready', $document->status);
        self::assertSame(0, DB::table('credit_ledger')->count());
        self::assertSame(0, DB::table('ocr_results')->count());
        self::assertSame(0, DB::table('document_ai_runs')->count());
    }

    public function test_words_below_the_confidence_threshold_never_reach_storage(): void
    {
        $document = $this->document();
        $this->runner(new FakeWordBoxRunner([
            new WordBox('Total', 10, 10, 50, 20, 0.95, '1:1:1'),
            new WordBox('arnount', 70, 10, 60, 20, 0.42, '1:1:1'),   // a guess, not a reading
            new WordBox('payable', 140, 10, 70, 20, 0.60, '1:1:1'),  // exactly at the threshold
        ]));

        (new LocateImageTextJob($document->id))->handle(app(ImageTextLocator::class),
            app(PipelineStageRecorder::class));

        self::assertSame(['Total', 'payable'], $this->anchors($document)->pluck('source_text')->all());
        self::assertSame(1, $this->stageRows($document)[0]->output['words_dropped']);
    }

    public function test_a_missing_binary_degrades_without_a_stage_row_or_a_failed_document(): void
    {
        $document = $this->document();
        $this->app->forgetInstance(TesseractBinary::class);
        config(['ocr.tesseract_binary' => '/nonexistent/tesseract']);
        $this->runner(new FakeWordBoxRunner(FakeWordBoxRunner::line(['Total', 'amount'])));

        (new LocateImageTextJob($document->id))->handle(app(ImageTextLocator::class),
            app(PipelineStageRecorder::class));

        self::assertCount(0, $this->anchors($document));
        self::assertSame('skipped', $this->stageRows($document)[0]->status);
        self::assertSame('tesseract_unavailable', $this->stageRows($document)[0]->output['reason']);
        $document->refresh();
        self::assertSame('Ready', $document->status);
        self::assertNull($document->error_message);
        self::assertSame(0, DB::table('credit_ledger')->count());
    }

    public function test_a_timeout_completes_the_optional_stage_rather_than_failing_it(): void
    {
        $document = $this->document();
        $this->runner(FakeWordBoxRunner::failing(new \RuntimeException(
            'Word-box OCR timed out after 45s.', 0,
            new ProcessTimedOutException(new Process(['true']), ProcessTimedOutException::TYPE_GENERAL))));

        (new LocateImageTextJob($document->id))->handle(app(ImageTextLocator::class),
            app(PipelineStageRecorder::class));

        $stage = $this->stageRows($document);
        self::assertCount(1, $stage);
        // Never 'failed': DocumentIntelligenceService::getDocumentFailure() reports
        // the latest failed row outside its own stage list as the document's
        // failure, so a failed row here would blame OCR for unrelated failures.
        self::assertSame('completed', $stage[0]->status);
        self::assertFalse($stage[0]->output['located']);
        self::assertSame('ocr_failed', $stage[0]->output['reason']);
        self::assertCount(0, $this->anchors($document));
        self::assertSame('Ready', $document->refresh()->status);
    }

    public function test_the_stage_never_fails_a_candidate_revision_or_blocks_publication(): void
    {
        config(['document_intelligence.revision_v1' => true]);
        $document = $this->document();
        $document->forceFill(['status' => 'Processing'])->save();
        $revision = app(DocumentRevisionPublisher::class)->begin($document->refresh());
        self::assertNotNull($revision);
        $this->runner(FakeWordBoxRunner::failing(new \RuntimeException('engine exploded')));

        (new LocateImageTextJob($document->id, $revision->id))->handle(
            app(ImageTextLocator::class),
            app(PipelineStageRecorder::class));

        $document->refresh();
        self::assertSame('candidate', DocumentIntelligenceRevision::find($revision->id)->state);
        self::assertSame($revision->id, $document->active_intelligence_revision_id);
        self::assertSame('Processing', $document->status);
        // Revision-scoped, and not a stage anything waits for.
        $stage = $this->stageRows($document)[0];
        self::assertSame($revision->id, $stage->intelligence_revision_id);
        self::assertNotSame('failed', $stage->status);
        self::assertFalse(app(DocumentRevisionPublisher::class)
            ->normalStagesComplete($document, $revision->id));
    }

    public function test_the_stage_is_not_in_any_required_stage_list(): void
    {
        $publisher = new \ReflectionMethod(DocumentRevisionPublisher::class, 'commit');
        $source = implode('', array_slice(file($publisher->getFileName()),
            $publisher->getStartLine() - 1, $publisher->getEndLine() - $publisher->getStartLine() + 1));

        self::assertStringNotContainsString(LocateImageTextJob::STAGE, $source);
        self::assertStringNotContainsString(LocateImageTextJob::STAGE,
            file_get_contents(base_path('app/Services/Pipeline/ProcessingStageReconciler.php')));
    }

    public function test_the_kill_switch_stops_the_stage_running_at_all(): void
    {
        config(['ocr.image_location.enabled' => false]);
        $document = $this->document();
        $runner = new FakeWordBoxRunner(FakeWordBoxRunner::line(['Total', 'amount']));
        $this->runner($runner);

        (new LocateImageTextJob($document->id))->handle(app(ImageTextLocator::class),
            app(PipelineStageRecorder::class));

        self::assertSame(0, $runner->calls);
        self::assertCount(0, $this->stageRows($document));
        self::assertCount(0, $this->anchors($document));
    }

    public function test_a_non_image_document_is_not_a_candidate_for_word_boxes(): void
    {
        $document = $this->document('PDF');
        $runner = new FakeWordBoxRunner(FakeWordBoxRunner::line(['Total', 'amount']));
        $this->runner($runner);

        (new LocateImageTextJob($document->id))->handle(app(ImageTextLocator::class),
            app(PipelineStageRecorder::class));

        self::assertSame(0, $runner->calls);
        self::assertCount(0, $this->anchors($document));
    }

    public function test_re_running_the_stage_supersedes_the_previous_reading(): void
    {
        $document = $this->document();
        $this->runner(new FakeWordBoxRunner(FakeWordBoxRunner::line(['Total', 'amount', 'payable'])));
        (new LocateImageTextJob($document->id))->handle(app(ImageTextLocator::class),
            app(PipelineStageRecorder::class));

        $this->runner(new FakeWordBoxRunner(FakeWordBoxRunner::line(['Total', 'amount'])));
        (new LocateImageTextJob($document->id))->handle(app(ImageTextLocator::class),
            app(PipelineStageRecorder::class));

        // One active set, one reading — no accumulation of stale boxes.
        self::assertSame(['Total', 'amount'], $this->anchors($document)->pluck('source_text')->all());
        self::assertSame(1, DB::table('source_provenance_sets')->where('document_id', $document->id)
            ->where('pipeline_key', ImageWordBoxStore::PIPELINE_KEY)->count());
    }
}
