<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The two additive contract fields must be present on every image source
 * response, including the common case of an image with no stored word boxes
 * at all. Deliberately free of any dependency on the OCR classes, so it
 * states the contract and nothing else.
 */
class ImageSourceContractFieldsTest extends TestCase
{
    use RefreshDatabase;

    private const TEXT = 'Total amount payable is due';

    /** @return array{0:User,1:Document,2:string} */
    private function fixture(string $type): array
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();
        $document = Document::create(['workspace_id' => $user->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => 'Scan.'.strtolower($type), 'type' => $type,
            'status' => 'Ready', 'classification' => 'Internal', 'size_kb' => 1, 'year' => 2026,
            'file_hash' => hash('sha256', 'contract-fixture'), 'file_path' => 'images/scan.jpg',
            'extracted_text' => self::TEXT, 'progress' => 100]);
        $quote = 'Total amount payable';
        $sourceId = 'kpi:'.(string) Str::uuid();
        DocumentEvidence::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'pipeline_key' => 'legacy',
            'identity' => hash('sha256', $quote), 'kind' => 'metric', 'source_id' => $sourceId,
            'data' => ['value' => '1', 'quote' => $quote],
            'sources' => [['quote' => $quote, 'start_offset' => 0,
                'end_offset' => mb_strlen($quote), 'span_id' => 'fixture']]]);

        return [$user, $document, $sourceId];
    }

    public function test_an_image_source_always_carries_text_located_and_regions(): void
    {
        [$user, $document, $sourceId] = $this->fixture('JPG');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/documents/'.$document->id.'/sources/'.$sourceId);

        $response->assertOk()->assertJsonStructure(['data' => ['textLocated', 'regions']]);
        // No OCR has run for this image, so a highlight would be invented.
        self::assertFalse($response->json('data.textLocated'));
        self::assertSame([], $response->json('data.regions'));
    }

    public function test_the_fields_are_additive_and_do_not_disturb_the_existing_response(): void
    {
        [$user, $document, $sourceId] = $this->fixture('PDF');
        Sanctum::actingAs($user);

        $this->getJson('/api/documents/'.$document->id.'/sources/'.$sourceId)
            ->assertOk()
            ->assertJsonStructure(['data' => ['textLocated', 'regions']])
            ->assertJsonPath('data.textLocated', false)
            ->assertJsonPath('data.regions', [])
            // Everything older clients read is unchanged.
            ->assertJsonPath('data.state', 'CURRENT')
            ->assertJsonPath('data.matchState', 'EXACT')
            ->assertJsonPath('data.capability', 'TEXTUAL')
            ->assertJsonPath('data.quality.locationQuality', 'exact_text_offset');
    }
}
