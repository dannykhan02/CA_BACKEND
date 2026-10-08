<?php

namespace Tests\Feature;

use App\Models\DocumentSourceSpan;
use App\Services\AnthropicClient;
use App\Services\Embeddings\VoyageEmbeddingClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsIntelligenceFixtures;
use Tests\TestCase;

class IntelligenceProvenanceDiagnosticCommandTest extends TestCase
{
    use BuildsIntelligenceFixtures, RefreshDatabase;

    public function test_json_command_uses_stored_projection_and_makes_no_writes_or_provider_calls(): void
    {
        app()->bind(AnthropicClient::class, fn () => throw new \LogicException('Provider reached'));
        app()->bind(VoyageEmbeddingClient::class, fn () => throw new \LogicException('Provider reached'));
        $document = $this->intelligenceDocument();
        $quote = 'Funding reached $1.9 million.';
        $pipeline = $document->ai_pipeline;
        $pipeline['extraction_version'] = 'synthetic-v1';
        $document->forceFill(['ai_pipeline' => $pipeline, 'extracted_text' => $quote])->save();
        $this->metricFinding($document, 'Funding', '$1.9M', null, null, ['quote' => $quote]);
        DocumentSourceSpan::create([
            'workspace_id' => $document->workspace_id, 'document_id' => $document->id,
            'extraction_version' => 'synthetic-v1', 'span_key' => 'E001', 'ordinal' => 1,
            'page' => 14, 'start_offset' => 0, 'end_offset' => mb_strlen($quote), 'type' => 'table_row',
        ]);
        $other = $this->intelligenceDocument();
        $this->metricFinding($other, 'Other funding', '$9M', null, null);

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });
        self::assertSame(0, Artisan::call('docintel:provenance-diagnostic',
            ['document' => $document->id, '--json' => true]));
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($document->id, $report['document']['id']);
        self::assertSame(1, $report['summary']['total_evidence']);
        self::assertSame(1, $report['summary']['unknown_origin_metrics']);
        self::assertSame(1, $report['unknown_reasons']['value_not_in_quote']);
        self::assertSame(1, $report['diagnostic_classifications']['numeric_equivalent']['count']);
        self::assertSame(0, $report['summary']['key_figure_eligible_count']);
        self::assertSame(0, array_sum($report['extended_diagnostic']['genuinely_unsupported']['subtype_counts']));
        self::assertSame(0, array_sum($report['extended_diagnostic']['ambiguous']['classification_counts']));
        self::assertSame('2026-10-08', $report['extended_diagnostic']['projection']['as_of']);
        self::assertSame(1, $report['extended_diagnostic']['projection']['conservative']['document_origin']);
        self::assertSame(1, $report['extended_diagnostic']['projection']['conservative']['key_figure_eligible_count']);
        foreach ($statements as $sql) {
            self::assertDoesNotMatchRegularExpression('/^\s*(?:insert|update|delete|create|alter|drop|truncate|dispatch)\b/i',
                $sql);
        }
    }
}
