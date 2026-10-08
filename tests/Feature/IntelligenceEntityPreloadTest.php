<?php

namespace Tests\Feature;

use App\Models\DocumentEntity;
use App\Services\AnthropicClient;
use App\Services\Intelligence\Materiality\MaterialityReadModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsIntelligenceFixtures;
use Tests\TestCase;

class IntelligenceEntityPreloadTest extends TestCase
{
    use BuildsIntelligenceFixtures, RefreshDatabase;

    public function test_entity_select_count_stays_one_as_record_count_grows(): void
    {
        app()->bind(AnthropicClient::class, fn () => throw new \LogicException('Provider reached'));
        Http::fake();
        $document = $this->intelligenceDocument();
        $other = $this->intelligenceDocument('Other entity report.pdf');
        foreach (['Agency', 'Ada'] as $value) {
            DocumentEntity::create(['workspace_id' => $document->workspace_id,
                'document_id' => $document->id, 'entity_type' => 'organization', 'value' => $value,
                'normalized_value' => strtolower($value), 'confidence' => 0.9, 'prompt_version' => 'test']);
            DocumentEntity::create(['workspace_id' => $other->workspace_id,
                'document_id' => $other->id, 'entity_type' => 'organization', 'value' => $value,
                'normalized_value' => strtolower($value), 'confidence' => 0.9, 'prompt_version' => 'test']);
        }
        $rows = [];
        foreach (range(1, 8) as $index) {
            $rows[] = $this->evidenceRow($document, 'fact', ['label' => 'Agency result '.$index,
                'value' => '12.4', 'subject' => 'Agency',
                'quote' => 'According to Ada, Agency result was 12.4.'], 'fact:'.$index);
        }
        $agencyId = DocumentEntity::where('document_id', $document->id)
            ->where('normalized_value', 'agency')->value('id');
        $speakerId = DocumentEntity::where('document_id', $document->id)
            ->where('normalized_value', 'ada')->value('id');

        $entitySelects = 0;
        DB::listen(static function ($query) use (&$entitySelects): void {
            if (str_contains(strtolower($query->sql), 'document_entities')) {
                $entitySelects++;
            }
        });
        $readModel = app(MaterialityReadModel::class);
        $one = $readModel->build($document, collect([$rows[0]]), [], []);
        self::assertSame(1, $entitySelects);
        $entitySelects = 0;
        $many = $readModel->build($document, collect($rows), [], []);
        self::assertSame(1, $entitySelects);
        self::assertCount(8, $many['records']);
        foreach ([$one['records'][0], ...$many['records']] as $record) {
            self::assertSame('entity:'.$agencyId, $record['typed']['value']['entity_ref']['id']);
            self::assertSame('quoted', $record['provenance']['attribution']['role']);
            self::assertSame('entity:'.$speakerId, $record['provenance']['attribution']['speaker']);
        }
        Http::assertNothingSent();
    }
}
