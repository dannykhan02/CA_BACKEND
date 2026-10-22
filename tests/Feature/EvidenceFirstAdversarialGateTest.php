<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Models\DocumentKpi;
use App\Models\User;
use App\Services\Intelligence\NormalKpiGrounder;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Independent synthetic claims. Every case is decided through metric persistence. */
class EvidenceFirstAdversarialGateTest extends TestCase
{
    use DatabaseTransactions;

    public static function claims(): iterable
    {
        $path = __DIR__.'/../Fixtures/intelligence-v2/evidence-first-adversarial-claims.json';
        $fixture = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        foreach ($fixture['cases'] as $case) {
            foreach ([false, true] as $enabled) {
                yield $case['id'].' flag_'.($enabled ? 'on' : 'off') => [$case, $enabled];
            }
        }
    }

    #[DataProvider('claims')]
    public function test_claim_matches_independent_synthetic_expectation(array $case, bool $enabled): void
    {
        config()->set('document_intelligence.server_evidence_resolution', $enabled);
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $text = $case['text'];
        $document = Document::create(['workspace_id' => $user->fresh()->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => 'synthetic-evidence-gate.pdf', 'type' => 'PDF',
            'status' => 'Processing', 'classification' => 'Internal', 'size_kb' => 1,
            'year' => 2026, 'pages' => 1, 'file_hash' => hash('sha256', $text),
            'extracted_text' => $text]);
        $document->forceFill(['ai_pipeline' => ['route' => 'normal', 'key' => 'normal:adversarial-gate',
            'normal_span_grounding' => true, 'text_hash' => hash('sha256', $text)]])->save();
        $claim = $case['claim'];
        $kpi = DocumentKpi::create(['document_id' => $document->id,
            'workspace_id' => $document->workspace_id, 'label' => $claim['label'],
            'value' => $claim['value'], 'unit' => $claim['unit'], 'period' => $claim['period'],
            'identity_metadata' => ['concept' => $claim['concept'], 'scope' => $claim['scope'],
                'metric_type' => $claim['unit'] === 'cases' ? 'count' : 'amount',
                'value_basis' => $claim['basis'], 'period' => $claim['period']]]);
        $rejection = app(NormalKpiGrounder::class)->record($document, $kpi,
            ['source_context' => $case['source_context']]);
        $evidence = DocumentEvidence::where('document_id', $document->id)
            ->where('source_id', 'kpi:'.$kpi->id)->first();
        if ($case['expected'] === 'accept') {
            self::assertNull($rejection, $case['id']);
            self::assertNotNull($evidence, $case['id']);
            self::assertSame(hash('sha256', $text), $evidence->data['source_revision']);
            $start = $evidence->data['source_cell_start_offset'];
            $end = $evidence->data['source_cell_end_offset'];
            self::assertSame($evidence->data['source_cell_value'], mb_substr($text, $start, $end - $start));
        } else {
            self::assertNotNull($rejection, $case['id']);
            self::assertNull($evidence, $case['id']);
        }
    }
}
