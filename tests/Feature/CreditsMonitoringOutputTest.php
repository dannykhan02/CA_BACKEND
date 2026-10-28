<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\OperationQuote;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** docintel:ai-credits-status knows the new settings; credits:audit-settlements counts the new rules over the last 7 days. */
class CreditsMonitoringOutputTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($this->user);
    }

    private function document(array $pipeline = [], string $status = 'Failed'): Document
    {
        return Document::create(['name' => 'Report.pdf', 'type' => 'PDF', 'size_kb' => 1, 'status' => $status, 'classification' => 'Public',
            'year' => 2026, 'workspace_id' => $this->workspace->id, 'uploaded_by' => $this->user->id, 'file_hash' => hash('sha256', uniqid('', true)),
            'ai_pipeline' => $pipeline]);
    }

    private function settledQuote(array $attributes): OperationQuote
    {
        $id = (string) Str::uuid();

        return OperationQuote::create($attributes + ['workspace_id' => $this->workspace->id, 'kind' => 'document', 'resource_id' => $id,
            'operation_key' => 'document:'.$id.':1', 'quote_version' => 'v3', 'band' => 'regular', 'credits' => 6, 'provider_cost_cap_usd' => 0.162,
            'preflight' => [], 'status' => 'settled', 'settled_at' => now()]);
    }

    public function test_b4_the_status_command_prints_the_settings_and_does_not_call_the_new_variables_deprecated(): void
    {
        config(['ai_credits.enabled' => true, 'ai_credits.settlement_mode' => 'actual', 'ai_credits.admission_min_ratio' => 0.4,
            'ai_credits.saved_fallback_during_subscription' => true, 'ai_credits.free_trial_days' => 30, 'ai_credits.grants.free_trial_credits' => 15,
            'ai_credits.plan_limits.free' => ['allowed_types' => 'pdf,docx', 'allow_ocr' => false, 'max_quote_tokens' => 18000],
            'ai_credits.grants.referral_reward_credits' => 30, 'ai_credits.grants.referral_reward_trigger' => 'first_payment']);
        $names = ['AI_CREDITS_FREE_ALLOWED_TYPES', 'AI_CREDITS_STARTER_MAX_QUOTE_TOKENS', 'AI_CREDITS_ADMISSION_MIN_RATIO', 'AI_CREDITS_SETTLEMENT_MODE',
            'AI_CREDITS_SAVED_FALLBACK', 'AI_CREDITS_FREE_TRIAL_DAYS', 'AI_CREDITS_FREE_QA_PER_DAY', 'AI_CREDITS_REFERRAL_TRIGGER', 'AI_CREDITS_REFERRAL_REWARD'];
        foreach ([...$names, 'AI_CREDITS_NOT_A_REAL_SETTING'] as $name) {
            $_SERVER[$name] = $_ENV[$name] = '1';
        }
        try {
            $this->artisan('docintel:ai-credits-status')
                ->expectsOutputToContain('Plan and admission settings')
                ->expectsOutputToContain('Settlement: mode actual')
                ->expectsOutputToContain('Admission minimum ratio 0.4')
                ->expectsOutputToContain('Free trial: 15 credits, expires after 30 days.')
                ->expectsOutputToContain('Referral reward: 30 saved credits, when the referred user\'s first paid subscription is active.')
                ->expectsOutputToContain('AI_CREDITS_NOT_A_REAL_SETTING')
                ->assertSuccessful();

            $output = $this->runAndCapture();
            $deprecated = collect(explode("\n", $output))->first(fn ($line) => str_contains($line, 'Deprecated (set, but'));
            foreach ($names as $name) {
                self::assertStringNotContainsString($name, (string) $deprecated, $name.' is a current setting');
            }
        } finally {
            foreach ([...$names, 'AI_CREDITS_NOT_A_REAL_SETTING'] as $name) {
                unset($_SERVER[$name], $_ENV[$name]);
            }
        }
    }

    private function runAndCapture(): string
    {
        \Illuminate\Support\Facades\Artisan::call('docintel:ai-credits-status');

        return \Illuminate\Support\Facades\Artisan::output();
    }

    public function test_b5_the_audit_counts_fallbacks_absorbed_credits_and_refusals_from_the_last_seven_days(): void
    {
        $this->settledQuote(['settlement_basis' => 'quoted_fallback', 'settlement_reason' => 'no_recorded_cost', 'charged_credits' => 6]);
        $this->settledQuote(['settlement_basis' => 'quoted_fallback', 'settlement_reason' => 'no_recorded_cost', 'charged_credits' => 6, 'settled_at' => now()->subDays(10)]);
        $absorbed = $this->settledQuote(['settlement_basis' => 'actual', 'charged_credits' => 3, 'absorbed_credits' => 7]);
        $this->document(['credit_block' => ['code' => 'insufficient_credits', 'at' => now()->toIso8601String()]]);
        $this->document(['credit_block' => ['code' => 'plan_limit', 'reason' => 'ocr_required', 'at' => now()->toIso8601String()]]);
        $this->document(['credit_block' => ['code' => 'plan_limit', 'reason' => 'document_size', 'at' => now()->subDays(9)->toIso8601String()]]);

        $this->artisan('credits:audit-settlements')
            ->expectsOutputToContain('Settled at the quoted amount (fallback): 1')
            ->expectsOutputToContain('- no_recorded_cost: 1')
            ->expectsOutputToContain('Absorbed credits (cost above the hold, not charged): 7 credits over 1 document(s)')
            ->expectsOutputToContain('- '.$absorbed->resource_id.': 7')
            ->expectsOutputToContain('Refused for credits or plan limits and still blocked: 2')
            ->expectsOutputToContain('- insufficient_credits: 1')
            ->expectsOutputToContain('- plan_limit:ocr_required: 1')
            ->assertSuccessful();
    }
}
