<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\OperationQuote;
use App\Models\User;
use App\Services\AiCredits\QuoteService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreditEconomicsReportTest extends TestCase
{
    use RefreshDatabase;

    private function report(array $options = []): array
    {
        Artisan::call('docintel:credit-economics-report', ['--json' => true] + $options);

        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_plan_margins_follow_the_ceiling_and_expose_the_annual_shortfall(): void
    {
        $plans = collect($this->report()['plans'])->keyBy(fn ($p) => $p['plan'].'.'.$p['interval']);
        $this->assertEqualsWithDelta(73.7, $plans['starter.monthly']['margin_at_cap'], 0.2);
        $this->assertTrue($plans['starter.monthly']['meets_target_at_cap']);
        $this->assertEqualsWithDelta(72.0, $plans['professional.monthly']['margin_at_cap'], 0.2);
        // Annual plans at the same monthly credits fall under the 70% target (the audit's finding).
        $this->assertEqualsWithDelta(69.0, $plans['starter.annual']['margin_at_cap'], 0.3);
        $this->assertFalse($plans['starter.annual']['meets_target_at_cap']);
        $this->assertFalse($plans['professional.annual']['meets_target_at_cap']);
        // Stress: +25% cost and +10% FX lowers every margin.
        $this->assertLessThan($plans['starter.monthly']['margin_at_cap'], $plans['starter.monthly']['margin_cost_fx_stress']);
    }

    public function test_assumptions_are_parameters_not_billing_inputs(): void
    {
        $report = $this->report(['--fx' => 150, '--fee' => 0.015, '--margin' => 0.8]);
        $this->assertEquals([150.0, 0.015, 0.8], [$report['assumptions']['fx_kes_per_usd'], $report['assumptions']['payment_fee_rate'], $report['assumptions']['margin_target']]);
        $this->assertSame(0, OperationQuote::count()); // read-only
    }

    public function test_operation_quantiles_unknown_runs_and_releases_are_aggregated_without_content(): void
    {
        config(['ai_credits.enabled' => true]);
        $user = User::factory()->create();
        $ws = app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $document = Document::create(['name' => 'SECRET-NAME.pdf', 'type' => 'PDF', 'size_kb' => 1, 'status' => 'Ready', 'classification' => 'Public', 'year' => 2026,
            'workspace_id' => $ws->id, 'uploaded_by' => $user->id, 'extracted_text' => 'SECRET TEXT '.str_repeat('x', 900)]);
        $quotes = app(QuoteService::class);
        foreach ([0.02, 0.04, 0.2] as $i => $cost) {
            $q = $quotes->issue($ws->id, 'document', (string) Str::uuid(), ['band' => 'standard', 'credits' => 10, 'cap' => 0.27, 'preflight' => []]);
            $q->update(['status' => 'settled']);
            DocumentAiRun::create(['workspace_id' => $ws->id, 'document_id' => $document->id, 'purpose' => 'entities', 'provider' => 'anthropic', 'model' => 'm',
                'estimated_cost_usd' => $cost, 'operation_quote_id' => $q->id, 'status' => 'success', 'created_at' => now()]);
            if ($i === 2) {
                DocumentAiRun::create(['workspace_id' => $ws->id, 'document_id' => $document->id, 'purpose' => 'entities', 'provider' => 'anthropic', 'model' => 'm',
                    'estimated_cost_usd' => null, 'operation_quote_id' => $q->id, 'status' => 'provider_error', 'created_at' => now()]);
                $q->update(['status' => 'released', 'release_reason' => 'document_failed']);
            }
        }
        $json = json_encode($report = $this->report());
        $row = collect($report['operations'])->firstWhere('band', 'standard');
        $this->assertSame([3, 2, 1, 30], [$row['quotes'], $row['settled'], $row['released'], $row['credits_quoted']]);
        $this->assertSame(0.04, $row['p50']);
        $this->assertSame(0.2, $row['max']);
        $this->assertSame([1, 0], [$row['unknown_runs'], $row['over_cap']]);
        $this->assertEqualsWithDelta(0.003, $row['usd_per_settled_credit'], 0.000001); // (0.02 + 0.04) / 20 settled credits
        $this->assertSame('document_failed', $report['releases'][0]['release_reason']);
        $this->assertStringNotContainsString('SECRET', $json);
    }

    public function test_what_if_mode_prints_scenarios_without_changing_config_or_data(): void
    {
        $before = config('ai_credits');
        $this->artisan('docintel:credit-economics-report', ['--what-if' => true, '--json' => true])->assertSuccessful();
        Artisan::call('docintel:credit-economics-report', ['--what-if' => true, '--json' => true, '--floor-share' => '0.5', '--bands' => '4:6000,15:30000,30:90000,60:250000']);
        $rows = json_decode(Artisan::output(), true);
        $this->assertSame([4, 15, 30, 60], array_column($rows, 'credits'));
        $this->assertSame([25, 6, 3, 1], array_column($rows, 'starter_docs_per_month'));
        Artisan::call('docintel:credit-economics-report', ['--what-if' => true, '--json' => true]);
        $today = json_decode(Artisan::output(), true);
        $this->assertSame([4, 30, 80, 80], array_column($today, 'credits')); // the memo's baseline
        $this->assertSame($before, config('ai_credits'));
        $this->assertSame(0, OperationQuote::count());
    }
}
