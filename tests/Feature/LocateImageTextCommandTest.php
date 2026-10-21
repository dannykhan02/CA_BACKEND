<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Services\Intelligence\ImageWordBoxStore;
use App\Services\Ocr\TesseractBinary;
use App\Services\Ocr\WordBoxRunner;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeWordBoxRunner;
use Tests\TestCase;

class LocateImageTextCommandTest extends TestCase
{
    use RefreshDatabase;

    private FakeWordBoxRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        config(['ocr.image_location.enabled' => true, 'ocr.image_location.min_word_confidence' => 60]);
        $this->app->instance(TesseractBinary::class, new class extends TesseractBinary
        {
            public function available(): bool
            {
                return true;
            }
        });
        $this->runner = new FakeWordBoxRunner(FakeWordBoxRunner::line(['Total', 'amount', 'payable']));
        $this->app->instance(WordBoxRunner::class, $this->runner);
    }

    private function document(): Document
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();

        $image = imagecreatetruecolor(300, 150);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $local = tempnam(sys_get_temp_dir(), 'img_cmd_');
        imagejpeg($image, $local);
        imagedestroy($image);
        Storage::disk('documents')->put('images/backfill.jpg', file_get_contents($local));
        @unlink($local);

        return Document::create(['workspace_id' => $user->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => 'Old.jpg', 'type' => 'JPG',
            'status' => 'Ready', 'classification' => 'Internal', 'size_kb' => 1, 'year' => 2026,
            'file_hash' => hash('sha256', 'cmd-fixture'), 'file_path' => 'images/backfill.jpg',
            'extracted_text' => 'Total amount payable', 'progress' => 100]);
    }

    private function storedBoxes(Document $document): int
    {
        return DB::table('source_anchors as a')
            ->join('source_provenance_sets as s', 's.id', '=', 'a.set_id')
            ->where('a.document_id', $document->id)
            ->where('s.pipeline_key', ImageWordBoxStore::PIPELINE_KEY)->count();
    }

    public function test_dry_run_is_the_default_and_writes_nothing(): void
    {
        $document = $this->document();

        $this->artisan('documents:ocr-locate-images')
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Nothing was written (dry run).')
            ->assertSuccessful();

        // The OCR ran, so the report is real...
        self::assertSame(1, $this->runner->calls);
        // ...but no box, and no provenance set, was persisted.
        self::assertSame(0, $this->storedBoxes($document));
        self::assertSame(0, DB::table('source_provenance_sets')
            ->where('pipeline_key', ImageWordBoxStore::PIPELINE_KEY)->count());
    }

    public function test_force_writes_the_word_boxes(): void
    {
        $document = $this->document();

        $this->artisan('documents:ocr-locate-images', ['--force' => true])
            ->expectsOutputToContain('WRITING')
            ->expectsOutputToContain('Boxes were written.')
            ->assertSuccessful();

        self::assertSame(3, $this->storedBoxes($document));
    }

    public function test_documents_that_already_have_current_boxes_are_skipped_unless_refreshed(): void
    {
        $document = $this->document();
        $this->artisan('documents:ocr-locate-images', ['--force' => true])->assertSuccessful();
        self::assertSame(1, $this->runner->calls);

        $this->artisan('documents:ocr-locate-images', ['--force' => true])
            ->expectsOutputToContain('No eligible image documents found.')
            ->assertSuccessful();
        self::assertSame(1, $this->runner->calls);

        $this->artisan('documents:ocr-locate-images', ['--force' => true, '--refresh' => true])
            ->assertSuccessful();
        self::assertSame(2, $this->runner->calls);
        self::assertSame(3, $this->storedBoxes($document));
    }

    public function test_the_limit_bounds_how_much_a_backfill_touches(): void
    {
        $this->document();
        $this->document();
        $this->document();

        $this->artisan('documents:ocr-locate-images', ['--force' => true, '--limit' => 2])
            ->assertSuccessful();

        self::assertSame(2, $this->runner->calls);
    }

    public function test_the_per_document_timeout_reaches_the_engine(): void
    {
        $this->document();

        $this->artisan('documents:ocr-locate-images', ['--timeout' => 7])->assertSuccessful();

        self::assertSame(7, $this->runner->lastTimeout);
    }

    public function test_the_command_refuses_to_run_while_the_kill_switch_is_off(): void
    {
        $document = $this->document();
        config(['ocr.image_location.enabled' => false]);

        $this->artisan('documents:ocr-locate-images', ['--force' => true])->assertFailed();

        self::assertSame(0, $this->runner->calls);
        self::assertSame(0, $this->storedBoxes($document));
    }

    public function test_the_command_refuses_to_run_without_the_binary(): void
    {
        $this->document();
        $this->app->forgetInstance(TesseractBinary::class);
        config(['ocr.tesseract_binary' => '/nonexistent/tesseract']);

        $this->artisan('documents:ocr-locate-images', ['--force' => true])->assertFailed();

        self::assertSame(0, $this->runner->calls);
    }
}
