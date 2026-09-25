<?php

namespace Tests\Feature;

use App\Enums\WorkspaceType;
use App\Jobs\GenerateInsightsJob;
use App\Models\AiPrompt;
use App\Models\Document;
use App\Models\DocumentKpi;
use App\Models\KpiAlias;
use App\Models\KpiDefinition;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\ResponseValidator;
use App\Services\AnthropicClient;
use App\Services\DocumentComparisonService;
use App\Services\Kpis\KpiIdentityProfile;
use App\Services\Kpis\KpiIdentityResolver;
use App\Services\Pipeline\PipelineStageRecorder;
use Database\Seeders\DocumentInsightsPromptSeederV7;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KpiIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['kpi_identity.semantic_matching' => false]);
        Http::preventStrayRequests();
    }

    private function workspace(): Workspace
    {
        return Workspace::create(['type' => WorkspaceType::Organization, 'name' => 'KPI test']);
    }

    private function document(Workspace $workspace, array $attributes = []): Document
    {
        return Document::create($attributes + [
            'workspace_id' => $workspace->id, 'uploaded_by' => User::factory()->create()->id,
            'name' => 'Metrics.pdf', 'type' => 'PDF', 'size_kb' => 1, 'status' => 'Ready',
            'classification' => 'Public', 'year' => 2026,
        ]);
    }

    private function resolve(Workspace $workspace, string $label, array $extra = []): array
    {
        return app(KpiIdentityResolver::class)->resolve($workspace->id, $extra + ['label' => $label]);
    }

    public function test_exact_case_and_punctuation_variants_reuse_one_identity(): void
    {
        $workspace = $this->workspace();
        $a = $this->resolve($workspace, 'Internal Charter Performance');
        foreach (['Internal Charter Performance', ' internal — charter: PERFORMANCE '] as $label) {
            $this->assertSame($a['definition_id'], $this->resolve($workspace, $label)['definition_id']);
        }
        $this->assertSame(1, KpiDefinition::count());
        Http::assertNothingSent();
    }

    public function test_production_charter_variants_and_reporting_periods_learn_aliases(): void
    {
        $workspace = $this->workspace();
        foreach ([
            ['Internal Service Charter Performance', 'Internal Charter Performance'],
            ['External Charter Tickets Closed Outside Target Timeline Q3', 'External Charter — Tickets Closed Outside Timelines'],
            ['External Service Charter Average Performance Q3 FY 2025/2026', 'External Charter — Average Performance'],
        ] as [$first, $second]) {
            $a = $this->resolve($workspace, $first);
            $b = $this->resolve($workspace, $second);
            $this->assertSame($a['definition_id'], $b['definition_id']);
            $this->assertSame('structured', $b['method']);
            $this->assertSame('alias', $this->resolve($workspace, $second)['method']);
        }
        $this->assertSame(3, KpiDefinition::count());
        $this->assertSame(6, KpiAlias::count());
        Http::assertNothingSent();
    }

    public function test_conflicting_contexts_with_identical_labels_have_separate_aliases(): void
    {
        $workspace = $this->workspace();
        $count = $this->resolve($workspace, 'Tickets Closed', ['unit' => 'tickets']);
        $rate = $this->resolve($workspace, 'Tickets Closed', ['unit' => '%']);
        $this->assertNotSame($count['definition_id'], $rate['definition_id']);
        $this->assertSame($count['definition_id'], $this->resolve($workspace, 'Tickets Closed', ['unit' => 'tickets'])['definition_id']);
        $this->assertSame(2, KpiAlias::count());
        $this->assertNull($this->resolve($workspace, 'Internal Charter Performance', ['identity' => ['scope' => 'external']])['definition_id']);
    }

    public function test_known_alias_resolves_without_ai_and_does_not_affect_other_workspaces(): void
    {
        $workspace = $this->workspace();
        $definition = $this->resolve($workspace, 'Average Case Resolution Time', ['unit' => 'days']);
        $alias = ['label' => 'Average Case Handling Time', 'unit' => 'days'];
        $profile = app(KpiIdentityProfile::class)->make($alias);
        KpiAlias::create([
            'workspace_id' => $workspace->id, 'kpi_definition_id' => $definition['definition_id'],
            'label' => $alias['label'], 'normalized_label' => $profile['normalized_label'],
            'context_key' => $profile['context_key'], 'matching_metadata' => $profile, 'method' => 'semantic',
        ]);
        $this->assertSame($definition['definition_id'], $this->resolve($workspace, $alias['label'], ['unit' => 'days'])['definition_id']);
        $other = $this->resolve($this->workspace(), $alias['label'], ['unit' => 'days']);
        $this->assertNotSame($definition['definition_id'], $other['definition_id']);
        Http::assertNothingSent();
    }

    public function test_database_rejects_cross_workspace_aliases(): void
    {
        $a = $this->workspace();
        $b = $this->workspace();
        $resolved = $this->resolve($a, 'Revenue');
        $this->expectException(QueryException::class);
        DB::table('kpi_aliases')->insert([
            'workspace_id' => $b->id, 'kpi_definition_id' => $resolved['definition_id'], 'label' => 'Sales',
            'normalized_label' => 'sales', 'context_key' => str_repeat('0', 64), 'matching_metadata' => '{}', 'method' => 'semantic',
        ]);
    }

    public function test_database_rejects_cross_workspace_observation_identity(): void
    {
        $a = $this->workspace();
        $b = $this->workspace();
        $definition = $this->resolve($a, 'Revenue');
        $doc = $this->document($b);
        $this->expectException(QueryException::class);
        DB::table('document_kpis')->insert(['workspace_id' => $b->id, 'document_id' => $doc->id,
            'kpi_definition_id' => $definition['definition_id'], 'label' => 'Revenue', 'value' => '10']);
    }

    public function test_learned_semantic_alias_avoids_repeated_ai_and_audits_token_usage(): void
    {
        config(['kpi_identity.semantic_matching' => true]);
        $workspace = $this->workspace();
        $known = $this->resolve($workspace, 'Average Case Resolution Time', ['unit' => 'days']);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode(['relationship' => 'same', 'candidate_id' => $known['definition_id'], 'confidence' => 0.99])]],
            'usage' => ['input_tokens' => 300, 'output_tokens' => 40],
        ])]);
        $doc = $this->document($workspace);
        $resolver = app(KpiIdentityResolver::class);
        $input = [['label' => 'Average Case Handling Time', 'unit' => 'days']];
        $first = $resolver->forDocument($doc, $input);
        $second = $resolver->forDocument($this->document($workspace), $input);
        $this->assertSame($known['definition_id'], $first[0]['kpi_definition_id']);
        $this->assertSame($known['definition_id'], $second[0]['kpi_definition_id']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['max_tokens'] === 400 && ! str_contains($request->body(), 'extracted_text'));
        $this->assertDatabaseHas('document_ai_runs', ['document_id' => $doc->id, 'purpose' => 'kpi_identity', 'input_tokens' => 300, 'output_tokens' => 40]);
        $this->assertSame('semantic', KpiAlias::where('label', $input[0]['label'])->sole()->method);
    }

    public function test_semantic_matching_is_bounded_and_related_metrics_stay_separate(): void
    {
        config(['kpi_identity.semantic_matching' => true]);
        $workspace = $this->workspace();
        $known = $this->resolve($workspace, 'Average Case Resolution Time', ['unit' => 'days']);
        $this->mock(AnthropicClient::class)->shouldReceive('adjudicateKpiIdentity')->once()->andReturn(['relationship' => 'related', 'candidate_id' => null, 'confidence' => 1]);
        $resolved = app(KpiIdentityResolver::class)->forDocument($this->document($workspace), [
            ['label' => 'Average Case Handling Time', 'unit' => 'days'],
            ['label' => 'Average Case Processing Time', 'unit' => 'days'],
        ]);
        $this->assertNotSame($known['definition_id'], $resolved[0]['kpi_definition_id']);
        $this->assertNotSame($resolved[0]['kpi_definition_id'], $resolved[1]['kpi_definition_id']);
    }

    public function test_semantic_matching_cannot_override_explicit_conflicts(): void
    {
        config(['kpi_identity.semantic_matching' => true]);
        $workspace = $this->workspace();
        $this->resolve($workspace, 'Monthly Service Fee', ['unit' => 'KES']);
        $this->resolve($workspace, 'Internal Charter Performance');
        $this->mock(AnthropicClient::class)->shouldNotReceive('adjudicateKpiIdentity');
        $resolved = app(KpiIdentityResolver::class)->forDocument($this->document($workspace), [
            ['label' => 'Monthly service fee increase', 'unit' => 'KES'],
            ['label' => 'External Charter Performance'],
        ]);
        $this->assertCount(2, $resolved);
        $this->assertSame(4, KpiDefinition::count());
    }

    public function test_provider_failure_keeps_observation_separate_and_does_not_retry(): void
    {
        config(['kpi_identity.semantic_matching' => true]);
        $workspace = $this->workspace();
        $this->resolve($workspace, 'Average Case Resolution Time', ['unit' => 'days']);
        Http::fake(['api.anthropic.com/*' => Http::response([], 503)]);
        $result = app(KpiIdentityResolver::class)->forDocument($this->document($workspace), [['label' => 'Average Case Handling Time', 'unit' => 'days']]);
        $this->assertNotNull($result[0]['kpi_definition_id']);
        $this->assertSame(2, KpiDefinition::count());
        Http::assertSentCount(1);
    }

    public function test_extraction_preserves_original_label_and_metadata_and_reuses_identity(): void
    {
        $workspace = $this->workspace();
        $workspace->credits()->update(['documents_remaining' => 5]);
        $known = $this->resolve($workspace, 'Internal Charter Performance Q1', ['unit' => '%']);
        $label = ' Internal Service Charter Performance Q2 FY 2025/2026 ';
        $identity = ['concept' => 'service charter performance', 'scope' => 'internal', 'metric_type' => 'percentage', 'period' => 'Q2 FY 2025/2026'];
        $doc = $this->document($workspace, ['status' => 'Processing', 'extracted_text' => 'Performance is 80%.']);
        $this->mock(AnthropicClient::class)->shouldReceive('extractDocumentInsights')->once()->andReturn([
            'kpis' => [['label' => $label, 'value' => '80%', 'unit' => '%', 'identity' => $identity]], 'charts' => [], 'insights' => [],
        ]);
        (new GenerateInsightsJob($doc->id))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));
        $row = $doc->kpis()->sole();
        $this->assertSame('Ready', $doc->fresh()->status);
        $this->assertSame($label, $row->label);
        $this->assertSame($known['definition_id'], $row->kpi_definition_id);
        $this->assertEquals($identity, $row->identity_metadata);
        $this->assertSame('Q2 FY 2025/2026', $row->period);
    }

    public function test_backfill_preview_is_read_only_and_apply_is_conservative_and_idempotent(): void
    {
        $workspace = $this->workspace();
        $a = $this->document($workspace);
        $b = $this->document($workspace);
        foreach ([[$a, 'Internal Service Charter Performance'], [$b, 'Internal Charter Performance'], [$a, 'Revenue'], [$b, 'Revenue'], [$a, 'Unclear metric']] as [$doc, $label]) {
            $doc->kpis()->create(['workspace_id' => $workspace->id, 'label' => $label, 'value' => '10']);
        }
        $other = $this->document($this->workspace());
        $other->kpis()->create(['workspace_id' => $other->workspace_id, 'label' => 'Internal Charter Performance', 'value' => '9']);
        $labels = DocumentKpi::orderBy('id')->pluck('label')->all();
        $this->artisan('kpis:backfill-identities', ['--workspace' => $workspace->id])->assertSuccessful();
        $this->assertSame(0, KpiDefinition::count());
        $this->assertSame(0, KpiAlias::count());
        $this->assertSame(0, DocumentKpi::whereNotNull('kpi_definition_id')->count());
        $this->artisan('kpis:backfill-identities', ['--workspace' => $workspace->id, '--apply' => true])->assertSuccessful();
        $this->assertSame(4, DocumentKpi::whereNotNull('kpi_definition_id')->count());
        $this->assertSame(2, KpiDefinition::count());
        $this->assertNull(DocumentKpi::where('label', 'Unclear metric')->sole()->kpi_definition_id);
        $this->assertNull($other->kpis()->sole()->kpi_definition_id);
        $this->artisan('kpis:backfill-identities', ['--workspace' => $workspace->id, '--apply' => true])->assertSuccessful();
        $this->assertSame(2, KpiDefinition::count());
        $this->assertSame($labels, DocumentKpi::orderBy('id')->pluck('label')->all());
        Http::assertNothingSent();
    }

    public function test_structured_comparison_uses_canonical_identity_and_keeps_legacy_fallback(): void
    {
        $workspace = $this->workspace();
        $a = $this->document($workspace);
        $b = $this->document($workspace);
        foreach ([[$a, 'Internal Service Charter Performance', '80'], [$b, 'Internal Charter Performance', '90']] as [$doc, $label, $value]) {
            $id = $this->resolve($workspace, $label)['definition_id'];
            $doc->kpis()->create(['workspace_id' => $workspace->id, 'label' => $label, 'value' => $value, 'kpi_definition_id' => $id]);
            $doc->kpis()->create(['workspace_id' => $workspace->id, 'label' => $doc === $a ? ' Legacy  Revenue ' : 'legacy revenue', 'value' => '10']);
        }
        $service = app(DocumentComparisonService::class);
        $changes = $service->changes($service->snapshot($a), $service->snapshot($b));
        $this->assertCount(1, $changes);
        $this->assertSame('Internal Service Charter Performance', $changes[0]['before'][0]['label']);
        $this->assertSame('Internal Charter Performance', $changes[0]['after'][0]['label']);
    }

    public function test_deleting_definition_preserves_observation_and_restores_fallback(): void
    {
        $workspace = $this->workspace();
        $doc = $this->document($workspace);
        $id = $this->resolve($workspace, 'Revenue')['definition_id'];
        $row = $doc->kpis()->create(['workspace_id' => $workspace->id, 'label' => 'Revenue', 'value' => '10', 'kpi_definition_id' => $id]);
        KpiDefinition::findOrFail($id)->delete();
        $this->assertNull($row->fresh()->kpi_definition_id);
        $this->assertSame('Revenue', $row->fresh()->label);
        $this->assertSame($workspace->id, $row->fresh()->workspace_id);
    }

    public function test_workspace_deletion_preserves_legacy_child_deletion_behavior(): void
    {
        $workspace = $this->workspace();
        $doc = $this->document($workspace);
        $id = $this->resolve($workspace, 'Revenue')['definition_id'];
        $row = $doc->kpis()->create(['workspace_id' => $workspace->id, 'label' => 'Revenue', 'value' => '10', 'kpi_definition_id' => $id]);
        $workspace->delete();
        DB::statement('SET CONSTRAINTS document_kpis_kpi_definition_id_foreign IMMEDIATE');
        $this->assertSame('Revenue', $row->fresh()->label);
        $this->assertNull($row->fresh()->workspace_id);
        $this->assertNull($row->fresh()->kpi_definition_id);
        $this->assertSame(0, KpiAlias::count());
    }

    public function test_additive_migration_preserves_populated_legacy_observations(): void
    {
        $workspace = $this->workspace();
        $doc = $this->document($workspace);
        $label = '  Original — Revenue (%)  ';
        $row = $doc->kpis()->create(['workspace_id' => $workspace->id, 'label' => $label, 'value' => '10']);
        // In production these legacy inserts have committed before DDL begins.
        DB::statement('SET CONSTRAINTS document_kpis_kpi_definition_id_foreign IMMEDIATE');
        $migration = require database_path('migrations/2026_09_25_000001_add_canonical_kpi_identity.php');
        $migration->down();
        $migration->up();
        $this->assertSame($label, $row->fresh()->label);
        $this->assertNull($row->fresh()->kpi_definition_id);
        $this->assertSame(0, KpiDefinition::count());
    }

    public function test_v7_prompt_is_optional_idempotent_and_extends_the_existing_response(): void
    {
        $this->seed(DocumentInsightsPromptSeederV7::class);
        $v7 = AiPrompt::where('name', 'document_insights')->where('version', 7)->sole();
        $this->assertFalse($v7->active);
        $this->assertStringContainsString('"identity": {"concept":', $v7->template);
        $this->assertStringContainsString('NUMERIC value', $v7->template);
        AiPrompt::activate('document_insights', 7);
        $this->seed(DocumentInsightsPromptSeederV7::class);
        $this->assertTrue($v7->fresh()->active);
        app(ResponseValidator::class)->validateKpiIdentities([['label' => 'Revenue'], ['identity' => ['concept' => 'revenue', 'scope' => null]]]);
        $this->expectException(\RuntimeException::class);
        app(ResponseValidator::class)->validateKpiIdentities([['identity' => ['concept' => ['invalid']]]]);
    }
}
