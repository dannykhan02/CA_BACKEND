<?php

namespace Tests\Feature;

use App\Jobs\LocateImageTextJob;
use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Models\User;
use App\Services\Ocr\ImageTextLocator;
use App\Services\Ocr\TesseractBinary;
use App\Services\Ocr\WordBox;
use App\Services\Ocr\WordBoxRunner;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeWordBoxRunner;
use Tests\TestCase;

/**
 * The additive image source contract where boxes exist: data.textLocated and
 * data.regions, normalised 0..1 against the image *after* EXIF orientation.
 *
 * ImageSourceContractFieldsTest covers the field shape with no OCR at all.
 */
class ImageSourceRegionApiTest extends TestCase
{
    use RefreshDatabase;

    private const TEXT = 'Invoice total amount payable is due';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        config(['ocr.image_location.enabled' => true,
            'ocr.image_location.min_word_confidence' => 60,
            'ocr.image_location.min_matched_words' => 2]);
        $this->app->instance(TesseractBinary::class, new class extends TesseractBinary
        {
            public function available(): bool
            {
                return true;
            }
        });
    }

    /** @return array{0:User,1:Document,2:string} */
    private function fixture(string $quote, string $type = 'JPG'): array
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();

        $image = imagecreatetruecolor(500, 250);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $local = tempnam(sys_get_temp_dir(), 'img_api_');
        imagejpeg($image, $local);
        imagedestroy($image);
        Storage::disk('documents')->put('images/invoice.jpg', file_get_contents($local));
        @unlink($local);

        $document = Document::create(['workspace_id' => $user->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => 'Invoice.jpg', 'type' => $type,
            'status' => 'Ready', 'classification' => 'Internal', 'size_kb' => 1, 'year' => 2026,
            'file_hash' => hash('sha256', 'api-fixture'), 'file_path' => 'images/invoice.jpg',
            'extracted_text' => self::TEXT, 'progress' => 100]);

        $sourceId = 'kpi:'.(string) Str::uuid();
        $start = mb_strpos(self::TEXT, $quote);
        self::assertNotFalse($start, 'The fixture quote must occur in the extracted text.');
        DocumentEvidence::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'pipeline_key' => 'legacy',
            'identity' => hash('sha256', $quote), 'kind' => 'metric', 'source_id' => $sourceId,
            'data' => ['value' => '1', 'quote' => $quote],
            'sources' => [['quote' => $quote, 'start_offset' => $start,
                'end_offset' => $start + mb_strlen($quote), 'span_id' => 'fixture']]]);

        return [$user, $document, $sourceId];
    }

    private function locate(Document $document, array $words): void
    {
        $this->app->instance(WordBoxRunner::class, new FakeWordBoxRunner($words));
        (new LocateImageTextJob($document->id))->handle(app(ImageTextLocator::class),
            app(PipelineStageRecorder::class));
    }

    public function test_a_located_quote_returns_merged_line_boxes_normalised_to_the_oriented_image(): void
    {
        [$user, $document, $sourceId] = $this->fixture('total amount payable');
        // Image is 500x250. "total amount" on line 1, "payable" on line 2.
        $this->locate($document, [
            new WordBox('Invoice', 10, 10, 60, 20, 0.95, '1:1:1'),
            new WordBox('total', 80, 10, 50, 20, 0.95, '1:1:1'),
            new WordBox('amount', 140, 10, 60, 20, 0.92, '1:1:1'),
            new WordBox('payable', 80, 50, 70, 20, 0.88, '1:1:2'),
            new WordBox('is', 160, 50, 20, 20, 0.90, '1:1:2'),
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/documents/'.$document->id.'/sources/'.$sourceId);

        $response->assertOk()
            ->assertJsonPath('data.textLocated', true)
            // Line 1: x 80..200 of 500 -> 0.16 wide 0.24; y 10..30 of 250 -> 0.04 high 0.08.
            ->assertJsonPath('data.regions.0', ['x' => 0.16, 'y' => 0.04,
                'width' => 0.24, 'height' => 0.08])
            // Line 2: only "payable" was cited, so "is" is not covered.
            ->assertJsonPath('data.regions.1', ['x' => 0.16, 'y' => 0.2,
                'width' => 0.14, 'height' => 0.08]);
        self::assertCount(2, $response->json('data.regions'));
        // The highlight is additive: it does not upgrade any existing claim.
        $response->assertJsonPath('data.matchState', 'EXACT')
            ->assertJsonPath('data.capability', 'TEXTUAL');
    }

    public function test_a_quote_the_word_boxes_do_not_contain_is_reported_as_not_located(): void
    {
        [$user, $document, $sourceId] = $this->fixture('total amount payable');
        $this->locate($document, FakeWordBoxRunner::line(['Something', 'entirely', 'different']));
        Sanctum::actingAs($user);

        $this->getJson('/api/documents/'.$document->id.'/sources/'.$sourceId)
            ->assertOk()
            ->assertJsonPath('data.textLocated', false)
            ->assertJsonPath('data.regions', [])
            // Still an honest exact text citation; only the highlight is missing.
            ->assertJsonPath('data.matchState', 'EXACT');
    }

    public function test_boxes_stop_backing_a_highlight_once_the_extracted_text_changes(): void
    {
        [$user, $document, $sourceId] = $this->fixture('total amount payable');
        $this->locate($document, [
            new WordBox('total', 80, 10, 50, 20, 0.95, '1:1:1'),
            new WordBox('amount', 140, 10, 60, 20, 0.92, '1:1:1'),
            new WordBox('payable', 210, 10, 70, 20, 0.88, '1:1:1'),
        ]);
        Sanctum::actingAs($user);
        $this->getJson('/api/documents/'.$document->id.'/sources/'.$sourceId)
            ->assertJsonPath('data.textLocated', true);

        // Re-extraction produces a new source revision; the old reading no longer
        // answers for text it never saw.
        $document->forceFill(['extracted_text' => self::TEXT.' revised'])->save();

        $this->getJson('/api/documents/'.$document->id.'/sources/'.$sourceId)
            ->assertOk()
            ->assertJsonPath('data.textLocated', false)
            ->assertJsonPath('data.regions', []);
    }
}
