<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Services\Ocr\ClaudeVisionOcrProvider;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The OCR batch job hands the provider an extensionless temp copy of the stored file (tempnam), so the media type
 * sent to Anthropic must come from the image bytes, not the file name.
 */
class OcrImageMediaTypeTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        config(['document_processing.image_max_pixels' => 40000000]);
        $this->dir = sys_get_temp_dir().'/ocr_media_'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function document(): Document
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);

        return Document::create(['name' => 'scan.jpg', 'type' => 'JPG', 'size_kb' => 1, 'status' => 'Processing', 'classification' => 'Public',
            'year' => 2026, 'workspace_id' => $user->fresh()->current_workspace_id, 'uploaded_by' => $user->id]);
    }

    private function image(string $name, string $format): string
    {
        $image = imagecreatetruecolor(40, 60);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 200, 200));
        $path = $this->dir.'/'.$name;
        match ($format) {
            'jpeg' => imagejpeg($image, $path),
            'png' => imagepng($image, $path),
        };
        imagedestroy($image);

        return $path;
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function files(): array
    {
        return [
            '.jpg' => ['page.jpg', 'jpeg', 'image/jpeg'],
            '.jpeg' => ['page.jpeg', 'jpeg', 'image/jpeg'],
            '.JPG' => ['PAGE.JPG', 'jpeg', 'image/jpeg'],
            '.png' => ['page.png', 'png', 'image/png'],
            'jpeg bytes, no extension (the temp copy the job passes)' => ['ocr_src_Ab12Cd', 'jpeg', 'image/jpeg'],
            'png bytes, no extension' => ['ocr_src_Ef34Gh', 'png', 'image/png'],
            'jpeg bytes, wrong .png name' => ['mislabelled.png', 'jpeg', 'image/jpeg'],
        ];
    }

    /** @dataProvider files */
    public function test_media_type_follows_the_bytes_and_data_is_the_file_bytes(string $name, string $format, string $expected): void
    {
        $path = $this->image($name, $format);
        Http::fake(['api.anthropic.com/*' => Http::response(['id' => 'ocr', 'model' => 'claude-haiku-4-5-20251001', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => json_encode(['text' => 'page one', 'confidence' => 0.9])]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 10]])]);

        $result = app(ClaudeVisionOcrProvider::class)->extractPage($path, $this->document());

        $this->assertSame('page one', $result->text);
        Http::assertSent(function (Request $request) use ($path, $expected) {
            $source = $request['messages'][0]['content'][0]['source'];

            return $source['type'] === 'base64'
                && $source['media_type'] === $expected
                && $source['data'] === base64_encode(file_get_contents($path));
        });
    }
}
