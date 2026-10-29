<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AiCredits\QaGuard;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Starter and Professional get their own daily Q&A caps; unset, they use the general limits. */
class QaPerPlanLimitsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai_credits.enabled' => true, 'ai_credits.qa.credits' => 0, 'ai_credits.qa.max_per_workspace_per_day' => 4,
            'ai_credits.qa.max_per_user_per_hour' => 1000, 'ai_credits.qa.max_cost_usd_per_workspace_per_day' => 2.0,
            'ai_credits.qa.free_max_per_workspace_per_day' => 4, 'ai_credits.qa.free_max_cost_usd_per_day' => 2.0]);
    }

    private function workspace(?string $plan): Workspace
    {
        $this->user = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($this->user);
        $this->user = $this->user->fresh();
        if ($plan !== null) {
            $sub = Subscription::create(['workspace_id' => $workspace->id, 'user_id' => $this->user->id, 'plan_key' => $plan,
                'billing_interval' => 'monthly', 'status' => 'active', 'current_period_start' => now()->subDay(), 'current_period_end' => now()->addMonth()]);
            $sub->periods()->create(['period_start' => now()->subDay(), 'period_end' => now()->addMonth(), 'documents_allowed' => 20,
                'comparisons_allowed' => 5, 'storage_bytes' => 1073741824, 'ai_credits_allowed' => 100]);
        }

        return $workspace;
    }

    private function spend(Workspace $workspace, string $cost): void
    {
        $document = Document::create(['name' => 'Report.pdf', 'type' => 'PDF', 'size_kb' => 1, 'status' => 'Ready', 'classification' => 'Public',
            'year' => 2026, 'workspace_id' => $workspace->id, 'uploaded_by' => $this->user->id, 'file_hash' => hash('sha256', uniqid('', true))]);
        DocumentAiRun::create(['workspace_id' => $workspace->id, 'document_id' => $document->id, 'file_hash' => $document->file_hash, 'purpose' => 'document_qa',
            'provider' => 'anthropic', 'model' => 'test', 'input_tokens' => 1, 'output_tokens' => 1, 'status' => 'success', 'estimated_cost_usd' => $cost,
            'created_at' => now()]);
    }

    private function askUntilStopped(int $max): array
    {
        $guard = app(QaGuard::class);
        for ($i = 0; $i < $max; $i++) {
            try {
                $guard->admit($this->user);
            } catch (HttpResponseException $e) {
                self::assertSame(429, $e->getResponse()->getStatusCode());

                return ['asked' => $i, 'body' => json_decode($e->getResponse()->getContent(), true)];
            } catch (HttpException $e) {
                self::assertSame(429, $e->getStatusCode());

                return ['asked' => $i, 'body' => null];
            }
        }
        self::fail('Expected a 429.');
    }

    public function test_the_plan_keys_exist_for_starter_and_professional(): void
    {
        foreach (['starter', 'professional'] as $plan) {
            self::assertIsInt(config("ai_credits.qa.plans.{$plan}.max_per_day"));
            self::assertIsFloat(config("ai_credits.qa.plans.{$plan}.max_cost_usd_per_day"));
        }
    }

    public function test_with_no_plan_keys_set_starter_and_professional_use_the_general_limits(): void
    {
        config(['ai_credits.qa.plans.starter.max_per_day' => 4, 'ai_credits.qa.plans.professional.max_per_day' => 4]);
        foreach (['starter', 'professional'] as $plan) {
            $this->workspace($plan);

            $result = $this->askUntilStopped(10);

            self::assertSame(4, $result['asked'], $plan);
        }
    }

    public function test_each_plans_own_daily_count_applies(): void
    {
        config(['ai_credits.qa.max_per_workspace_per_day' => 100, 'ai_credits.qa.plans.starter.max_per_day' => 3, 'ai_credits.qa.plans.professional.max_per_day' => 6]);

        $this->workspace('starter');
        self::assertSame(3, $this->askUntilStopped(10)['asked']);

        $this->workspace('professional');
        self::assertSame(6, $this->askUntilStopped(10)['asked']);
    }

    public function test_each_plans_own_daily_cost_cap_applies(): void
    {
        config(['ai_credits.qa.plans.starter.max_cost_usd_per_day' => 0.50, 'ai_credits.qa.plans.professional.max_cost_usd_per_day' => 1.50]);

        $starter = $this->workspace('starter');
        $this->spend($starter, '0.600000');
        self::assertSame('starter', $this->askUntilStopped(1)['body']['plan']);

        $pro = $this->workspace('professional');
        $this->spend($pro, '0.600000');
        self::assertNull(app(QaGuard::class)->admit($this->user));
    }

    public function test_the_429_payload_names_the_plan_and_the_plan_to_upgrade_to(): void
    {
        config(['ai_credits.qa.max_per_workspace_per_day' => 100, 'ai_credits.qa.plans.starter.max_per_day' => 1, 'ai_credits.qa.plans.professional.max_per_day' => 1,
            'ai_credits.qa.free_max_per_workspace_per_day' => 1]);
        $expected = ['free' => 'starter', 'starter' => 'professional', 'professional' => null];

        foreach ($expected as $plan => $required) {
            $this->workspace($plan === 'free' ? null : $plan);

            $body = $this->askUntilStopped(5)['body'];

            self::assertSame('plan_limit', $body['code']);
            self::assertSame('qa_daily_limit', $body['reason']);
            self::assertSame($plan, $body['plan']);
            self::assertSame($required, $body['required_plan']);
            self::assertSame(now()->addDay()->startOfDay()->toIso8601String(), $body['resets_at']);
        }
    }

    public function test_free_behaviour_is_unchanged(): void
    {
        config(['ai_credits.qa.max_per_workspace_per_day' => 100, 'ai_credits.qa.free_max_per_workspace_per_day' => 2,
            'ai_credits.qa.plans.starter.max_per_day' => 50]);
        $this->workspace(null);

        $result = $this->askUntilStopped(10);

        self::assertSame(2, $result['asked']);
        self::assertSame('free', $result['body']['plan']);
        self::assertSame('starter', $result['body']['required_plan']);
    }
}
