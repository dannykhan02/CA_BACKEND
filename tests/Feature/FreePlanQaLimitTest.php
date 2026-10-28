<?php

namespace Tests\Feature;

use App\Models\DocumentAiRun;
use App\Models\Document;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AiCredits\QaGuard;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Free workspaces get their own daily question limits; with nothing configured they equal everyone's, as before. */
class FreePlanQaLimitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai_credits.enabled' => true, 'ai_credits.qa.credits' => 0, 'ai_credits.qa.max_per_workspace_per_day' => 100,
            'ai_credits.qa.max_per_user_per_hour' => 1000, 'ai_credits.qa.max_cost_usd_per_workspace_per_day' => 2.0]);
    }

    private function workspace(): Workspace
    {
        $this->user = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($this->user);
        $this->user = $this->user->fresh();

        return $workspace;
    }

    private function subscribe(Workspace $workspace, string $plan): void
    {
        $sub = Subscription::create(['workspace_id' => $workspace->id, 'user_id' => $this->user->id, 'plan_key' => $plan,
            'billing_interval' => 'monthly', 'status' => 'active', 'current_period_start' => now()->subDay(), 'current_period_end' => now()->addMonth()]);
        $sub->periods()->create(['period_start' => now()->subDay(), 'period_end' => now()->addMonth(), 'documents_allowed' => 20,
            'comparisons_allowed' => 5, 'storage_bytes' => 1073741824, 'ai_credits_allowed' => 100]);
    }

    private function planLimitBody(callable $call): array
    {
        try {
            $call();
        } catch (HttpResponseException $e) {
            self::assertSame(429, $e->getResponse()->getStatusCode());

            return json_decode($e->getResponse()->getContent(), true);
        }
        self::fail('Expected the free plan limit.');
    }

    private function spend(Workspace $workspace, string $cost): void
    {
        $document = Document::create(['name' => 'Report.pdf', 'type' => 'PDF', 'size_kb' => 1, 'status' => 'Ready', 'classification' => 'Public',
            'year' => 2026, 'workspace_id' => $workspace->id, 'uploaded_by' => $this->user->id, 'file_hash' => hash('sha256', uniqid('', true))]);
        DocumentAiRun::create(['workspace_id' => $workspace->id, 'document_id' => $document->id, 'file_hash' => $document->file_hash, 'purpose' => 'document_qa',
            'provider' => 'anthropic', 'model' => 'test', 'input_tokens' => 1, 'output_tokens' => 1, 'status' => 'success', 'estimated_cost_usd' => $cost,
            'created_at' => now()]);
    }

    public function test_with_nothing_configured_the_free_limits_equal_the_general_ones(): void
    {
        self::assertSame(config('ai_credits.qa.max_per_workspace_per_day'), config('ai_credits.qa.free_max_per_workspace_per_day'));
        self::assertSame((float) config('ai_credits.qa.max_cost_usd_per_workspace_per_day'), (float) config('ai_credits.qa.free_max_cost_usd_per_day'));
    }

    public function test_a_free_workspace_gets_the_plan_limit_error_after_its_daily_questions(): void
    {
        config(['ai_credits.qa.free_max_per_workspace_per_day' => 10, 'ai_credits.qa.free_max_cost_usd_per_day' => 0.10]);
        $this->workspace();
        $guard = app(QaGuard::class);
        for ($i = 0; $i < 10; $i++) {
            self::assertNull($guard->admit($this->user));
        }

        $body = $this->planLimitBody(fn () => $guard->admit($this->user));

        self::assertSame('plan_limit', $body['code']);
        self::assertSame('qa_daily_limit', $body['reason']);
        self::assertSame('free', $body['plan']);
        self::assertSame('starter', $body['required_plan']);
        self::assertSame(now()->addDay()->startOfDay()->toIso8601String(), $body['resets_at']);
    }

    public function test_a_free_workspace_over_its_daily_spend_gets_the_plan_limit_error(): void
    {
        config(['ai_credits.qa.free_max_per_workspace_per_day' => 10, 'ai_credits.qa.free_max_cost_usd_per_day' => 0.10]);
        $workspace = $this->workspace();
        $this->spend($workspace, '0.120000');

        $body = $this->planLimitBody(fn () => app(QaGuard::class)->admit($this->user));

        self::assertSame('qa_daily_limit', $body['reason']);
        self::assertSame('free', $body['plan']);
    }

    public function test_a_starter_workspace_is_not_held_to_the_free_limits(): void
    {
        config(['ai_credits.qa.free_max_per_workspace_per_day' => 2, 'ai_credits.qa.free_max_cost_usd_per_day' => 0.10]);
        $workspace = $this->workspace();
        $this->subscribe($workspace, 'starter');
        $this->spend($workspace, '0.500000');
        $guard = app(QaGuard::class);

        for ($i = 0; $i < 5; $i++) {
            self::assertNull($guard->admit($this->user));
        }
    }

    public function test_the_general_caps_still_apply_as_before(): void
    {
        config(['ai_credits.qa.max_per_workspace_per_day' => 2]);
        $this->workspace();
        $guard = app(QaGuard::class);
        $guard->admit($this->user);
        $guard->admit($this->user);

        try {
            $guard->admit($this->user);
            self::fail('The general daily cap must still stop the third question.');
        } catch (HttpException $e) {
            self::assertSame(429, $e->getStatusCode());
        }
    }
}
