<?php

namespace Tests\Feature;

use App\Console\Commands\ReleaseStaleReservations;
use App\Jobs\ResumeAfterCreditConfirmationJob;
use App\Models\AiPrompt;
use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\OperationQuote;
use App\Models\Subscription;
use App\Models\SubscriptionUsagePeriod;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\PasswordResetNotification;
use App\Notifications\VerificationCodeNotification;
use App\Services\AI\ProviderGate;
use App\Services\AI\ProviderGate\MemoryGateStore;
use App\Services\AI\ProviderGate\RedisGateStore;
use App\Services\AiCredits\AiCreditAdmission;
use App\Services\AiCredits\CreditAccountant;
use App\Services\AiCredits\QaGuard;
use App\Services\AnthropicClient;
use App\Services\EntitlementService;
use App\Services\WorkspaceCreditService;
use App\Services\WorkspaceService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Final pre-scale audit (docs/tasks/final-audit.md): the billing and safety invariants the credits rollout relies on.
 * No provider is ever called; every HTTP request is faked.
 */
class FinalAuditInvariantsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai_credits.enabled' => true]);
    }

    private function workspace(int $savedAi = 0, int $legacyDocs = 0): Workspace
    {
        $this->user = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($this->user);
        $workspace->credits()->update(['documents_remaining' => $legacyDocs, 'ai_credits_remaining' => $savedAi]);
        Sanctum::actingAs($this->user->fresh());

        return $workspace;
    }

    private function subscribe(Workspace $workspace, ?int $aiCredits): SubscriptionUsagePeriod
    {
        $sub = Subscription::create(['workspace_id' => $workspace->id, 'user_id' => $this->user->id, 'plan_key' => 'starter',
            'billing_interval' => 'monthly', 'status' => 'active', 'current_period_start' => now()->subDay(), 'current_period_end' => now()->addMonth()]);

        return $sub->periods()->create(['period_start' => now()->subDay(), 'period_end' => now()->addMonth(), 'documents_allowed' => 20,
            'comparisons_allowed' => 5, 'storage_bytes' => 1073741824, 'ai_credits_allowed' => $aiCredits]);
    }

    private function document(Workspace $workspace, int $tokens = 1000, string $status = 'Processing', ?User $by = null): Document
    {
        return Document::create(['name' => 'Report.pdf', 'type' => 'PDF', 'size_kb' => 1, 'status' => $status, 'classification' => 'Public',
            'year' => 2026, 'workspace_id' => $workspace->id, 'uploaded_by' => ($by ?? $this->user)->id,
            'extracted_text' => str_repeat('Revenue grew in the quarter. ', (int) ceil($tokens * 3 / 30))]);
    }

    private function admit(Document $document): bool
    {
        return app(AiCreditAdmission::class)->admitDocument($document->fresh());
    }

    private function op(Document $document, string $kind = 'document'): ?object
    {
        return DB::table('billing_operations')->where('kind', $kind)->where('resource_id', $document->id)->first();
    }

    private function ledger(string $workspaceId, string $direction): int
    {
        return (int) DB::table('credit_ledger')->where('workspace_id', $workspaceId)->where('direction', $direction)
            ->whereIn('unit', ['subscription_ai_credit', 'saved_ai_credit'])->sum('amount');
    }

    // ---------------------------------------------------------------- A: flag flipping

    public function test_flag_turned_off_while_a_reservation_is_open_still_settles_the_quoted_credits(): void
    {
        $ws = $this->workspace();
        $period = $this->subscribe($ws, 100);
        $document = $this->document($ws, 20000);
        $this->assertTrue($this->admit($document));
        $this->assertSame(10, (int) $this->op($document)->amount_reserved);

        config(['ai_credits.enabled' => false]); // rollback while the analysis is in flight

        $credits = app(WorkspaceCreditService::class);
        $credits->accountForReadyDocument($document->fresh(), false); // merge time: evidence alone never debits
        $this->assertSame('reserved', $this->op($document)->status);
        $credits->accountForReadyDocument($document->fresh());        // Ready
        $credits->accountForReadyDocument($document->fresh());        // duplicate delivery

        $op = $this->op($document);
        $this->assertSame(['completed', 10], [$op->status, (int) $op->amount_settled]);
        $this->assertSame(10, (int) $period->fresh()->ai_credits_used);
        $this->assertSame(0, (int) $period->fresh()->documents_used, 'the legacy one-unit counter must not also be charged');
        $this->assertSame('settled', OperationQuote::firstOrFail()->status);
        $this->assertSame(10, $this->ledger($ws->id, 'debit'));
        $this->assertSame(0, DB::table('credit_ledger')->where('reference', 'like', 'document_usage:%')->count());
    }

    public function test_flag_turned_off_never_leaves_a_saved_credit_reservation_to_a_free_legacy_debit(): void
    {
        $ws = $this->workspace(50);
        $document = $this->document($ws, 20000);
        $this->assertTrue($this->admit($document));
        config(['ai_credits.enabled' => false]);

        app(WorkspaceCreditService::class)->accountForReadyDocument($document->fresh());

        $this->assertSame(40, (int) $ws->credits()->value('ai_credits_remaining'));
        $this->assertSame(0, (int) $ws->credits()->value('documents_remaining'));
        $this->assertSame('settled', OperationQuote::firstOrFail()->status);
    }

    public function test_flag_off_with_no_ai_operation_is_the_unchanged_legacy_debit(): void
    {
        config(['ai_credits.enabled' => false]);
        $ws = $this->workspace(0, 3);
        $document = $this->document($ws, 1000);
        app(EntitlementService::class)->reserveDocument($document);
        $this->assertFalse(app(WorkspaceCreditService::class)->hasAiOperation($document->id));
        app(WorkspaceCreditService::class)->accountForReadyDocument($document->fresh());
        $this->assertSame(2, (int) $ws->credits()->value('documents_remaining'));
        $this->assertSame(0, DB::table('operation_quotes')->count());
    }

    public function test_flag_turned_on_while_a_legacy_reservation_is_open_does_not_charge_twice(): void
    {
        config(['ai_credits.enabled' => false]);
        $ws = $this->workspace(50, 5);
        $document = $this->document($ws, 20000);
        app(EntitlementService::class)->reserveDocument($document);
        $this->assertNull($this->op($document)->amount_reserved);

        config(['ai_credits.enabled' => true]);
        $this->assertTrue($this->admit($document)); // re-admitted as one AI-credit operation, replacing the legacy reservation
        app(WorkspaceCreditService::class)->accountForReadyDocument($document->fresh());
        app(WorkspaceCreditService::class)->accountForReadyDocument($document->fresh());

        $this->assertSame(1, DB::table('billing_operations')->where('resource_id', $document->id)->count());
        $this->assertSame(1, OperationQuote::where('status', 'settled')->count());
        // 5 legacy units convert lazily to 50 credits on top of the 50 explicit ones; exactly 10 are spent.
        $this->assertSame(90, (int) $ws->credits()->value('ai_credits_remaining') + (int) $ws->credits()->value('documents_remaining') * 10);
    }

    public function test_ledger_reconciles_reserve_equals_debit_plus_release_plus_open_after_a_mixed_run_with_a_flag_flip(): void
    {
        $ws = $this->workspace(200);
        $settled = $this->document($ws, 20000);
        $failed = $this->document($ws, 20000);
        $open = $this->document($ws, 1000);
        foreach ([$settled, $failed, $open] as $document) {
            $this->assertTrue($this->admit($document));
        }
        config(['ai_credits.enabled' => false]);
        app(WorkspaceCreditService::class)->accountForReadyDocument($settled->fresh());
        $failed->update(['status' => 'Failed']);
        app(EntitlementService::class)->summary($ws->id);

        $reserved = $this->ledger($ws->id, 'reserve');
        $open = (int) DB::table('billing_operations')->where('workspace_id', $ws->id)->where('status', 'reserved')->sum('amount_reserved');
        $this->assertSame($reserved, $this->ledger($ws->id, 'debit') + $this->ledger($ws->id, 'release') + $open);
        $this->assertSame([10, 10, 4], [$this->ledger($ws->id, 'debit'), $this->ledger($ws->id, 'release'), $open]);
        $this->assertGreaterThanOrEqual(0, (int) $ws->credits()->value('ai_credits_remaining'));
    }

    // ---------------------------------------------------------------- A: confirm-credits route

    public function test_confirm_credits_is_refused_while_the_flag_is_off(): void
    {
        $ws = $this->workspace(100);
        $document = $this->document($ws, 50000);
        $this->assertFalse($this->admit($document));
        config(['ai_credits.enabled' => false]);
        $this->postJson("/api/documents/{$document->id}/confirm-credits")->assertStatus(409);
    }

    public function test_another_workspace_cannot_confirm_someone_elses_document(): void
    {
        Queue::fake();
        $ws = $this->workspace(100);
        $document = $this->document($ws, 50000);
        $this->assertFalse($this->admit($document));

        $this->workspace(100); // a different user, workspace and token
        $this->postJson("/api/documents/{$document->id}/confirm-credits")->assertStatus(403);
        $this->assertFalse($document->fresh()->ai_pipeline['credit_quote_confirmed'] ?? false);
        Queue::assertNothingPushed();
    }

    public function test_double_click_confirmation_resumes_the_analysis_once(): void
    {
        Queue::fake();
        $ws = $this->workspace(100);
        $document = $this->document($ws, 50000);
        $this->assertFalse($this->admit($document));

        $this->postJson("/api/documents/{$document->id}/confirm-credits")->assertStatus(202);
        $this->postJson("/api/documents/{$document->id}/confirm-credits")->assertStatus(202);

        Queue::assertPushed(ResumeAfterCreditConfirmationJob::class, 1);
        $this->assertNull($this->op($document), 'the click reserves nothing; the resumed job does');
    }

    public function test_confirmation_reports_a_balance_that_fell_below_the_quote_instead_of_failing_the_document_later(): void
    {
        Queue::fake();
        $ws = $this->workspace(100);
        $document = $this->document($ws, 50000); // 30 credits
        $this->assertFalse($this->admit($document));
        $ws->credits()->update(['ai_credits_remaining' => 12]); // spent elsewhere between quote and click

        $this->postJson("/api/documents/{$document->id}/confirm-credits")->assertStatus(402);

        $this->assertFalse($document->fresh()->ai_pipeline['credit_quote_confirmed'] ?? false);
        $this->assertSame('Processing', $document->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_a_lost_resume_after_confirmation_is_recovered_by_the_scheduler_command(): void
    {
        Queue::fake();
        $ws = $this->workspace(100);
        $confirmed = $this->document($ws, 50000);
        $waiting = $this->document($ws, 50000);
        foreach ([$confirmed, $waiting] as $document) {
            $this->assertFalse($this->admit($document));
        }
        app(AiCreditAdmission::class)->confirmDocument($confirmed->fresh());
        Document::query()->update(['updated_at' => now()->subHour()]);

        Artisan::call('docintel:resume');

        Queue::assertPushed(ResumeAfterCreditConfirmationJob::class, fn ($job) => $job->documentId === $confirmed->id);
        Queue::assertPushed(ResumeAfterCreditConfirmationJob::class, 1); // an unconfirmed document is never confirmed for the customer
    }

    public function test_a_document_waiting_when_the_flag_is_turned_off_resumes_and_stops_waiting(): void
    {
        Queue::fake();
        $ws = $this->workspace(100, 2);
        $document = $this->document($ws, 50000);
        $this->assertFalse($this->admit($document));
        config(['ai_credits.enabled' => false]);
        Document::query()->update(['updated_at' => now()->subHour()]);

        Artisan::call('docintel:resume');
        Queue::assertPushed(ResumeAfterCreditConfirmationJob::class, 1);

        $this->assertTrue($this->admit($document)); // the resumed job passes admission under the legacy rules
        $this->assertFalse($document->fresh()->ai_pipeline['awaiting_credit_confirmation']);
    }

    // ---------------------------------------------------------------- A/B: large re-analysis shows its price first

    public function test_a_customer_reanalysis_of_a_large_document_needs_the_exact_quote_confirmed(): void
    {
        $ws = $this->workspace(200);
        $document = $this->document($ws, 50000);
        $this->assertFalse($this->admit($document));
        app(AiCreditAdmission::class)->confirmDocument($document->fresh());
        $this->assertTrue($this->admit($document));
        app(WorkspaceCreditService::class)->accountForReadyDocument($document->fresh());
        $document->update(['status' => 'Ready']);
        $admission = app(AiCreditAdmission::class);

        foreach ([0, 5] as $wrong) {
            try {
                $admission->admitReanalysis($document->fresh(), false, $this->user->id, $wrong);
                $this->fail('A large re-analysis must not reserve without the confirmed price.');
            } catch (HttpResponseException $e) {
                $this->assertSame(409, $e->getResponse()->getStatusCode());
                $this->assertSame(30, json_decode($e->getResponse()->getContent(), true)['data']['credits']);
            }
        }
        $this->assertNull($this->op($document, 'reanalysis'));

        $admission->admitReanalysis($document->fresh(), false, $this->user->id, 30);
        $admission->admitReanalysis($document->fresh(), false, $this->user->id, 30); // double click
        $this->assertSame(30, (int) $this->op($document, 'reanalysis')->amount_reserved);
    }

    public function test_internal_and_operator_reanalysis_is_not_blocked_by_the_customer_confirmation(): void
    {
        $ws = $this->workspace(200);
        $document = $this->document($ws, 50000);
        $this->assertFalse($this->admit($document));
        app(AiCreditAdmission::class)->confirmDocument($document->fresh());
        $this->assertTrue($this->admit($document));
        app(WorkspaceCreditService::class)->accountForReadyDocument($document->fresh());
        $document->update(['status' => 'Ready']);

        app(AiCreditAdmission::class)->admitReanalysis($document->fresh(), false, $this->user->id); // null = operator command

        $this->assertSame(30, (int) $this->op($document, 'reanalysis')->amount_reserved);
    }

    // ---------------------------------------------------------------- B: release sweep

    public function test_expired_and_failed_reservations_are_released_by_the_scheduled_sweep_in_a_dormant_workspace(): void
    {
        $ws = $this->workspace(100);
        $expired = $this->document($ws, 20000);
        $failed = $this->document($ws, 20000);
        $live = $this->document($ws, 1000);
        foreach ([$expired, $failed, $live] as $document) {
            $this->assertTrue($this->admit($document));
        }
        DB::table('billing_operations')->where('resource_id', $expired->id)->update(['updated_at' => now()->subHours(25)]);
        $failed->update(['status' => 'Failed']);

        Artisan::call('billing:release-stale-reservations');
        Artisan::call('billing:release-stale-reservations'); // idempotent

        $this->assertSame(['released', 'released', 'reserved'],
            [$this->op($expired)->status, $this->op($failed)->status, $this->op($live)->status]);
        $this->assertSame(20, $this->ledger($ws->id, 'release'));
        $this->assertSame(['reservation_expired', 'document_failed'], [$this->op($expired)->release_reason, $this->op($failed)->release_reason]);
    }

    public function test_the_release_sweep_and_the_failed_job_prune_are_on_the_scheduler(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($event) => (string) $event->command);
        $this->assertTrue($commands->contains(fn ($c) => str_contains($c, 'billing:release-stale-reservations')));
        $this->assertTrue($commands->contains(fn ($c) => str_contains($c, 'queue:prune-failed')));
        $this->assertTrue($commands->contains(fn ($c) => str_contains($c, 'docintel:resume')));
        $this->assertTrue(class_exists(ReleaseStaleReservations::class));
    }

    public function test_concurrent_looking_settle_and_release_never_both_apply(): void
    {
        $ws = $this->workspace(100);
        $document = $this->document($ws, 20000);
        $this->assertTrue($this->admit($document));
        $accountant = app(CreditAccountant::class);

        $this->assertTrue($accountant->settle('document', $document->id));
        $this->assertFalse($accountant->release('document', $document->id, 'late_failure')); // released work is never settled and settled work is never released
        $this->assertFalse($accountant->settle('document', $document->id));
        $this->assertSame([10, 0], [$this->ledger($ws->id, 'debit'), $this->ledger($ws->id, 'release')]);
        $this->assertSame(90, (int) $ws->credits()->value('ai_credits_remaining'));
    }

    // ---------------------------------------------------------------- C: provider gate and Q&A

    private function gateWith(MemoryGateStore $store): ProviderGate
    {
        return new class($store) extends ProviderGate
        {
            public function __construct(private MemoryGateStore $fake) {}

            public function store(): RedisGateStore|MemoryGateStore
            {
                return $this->fake;
            }
        };
    }

    public function test_a_failing_permit_release_does_not_replace_a_paid_response(): void
    {
        $gate = $this->gateWith(new class extends MemoryGateStore
        {
            public function release(string $member): void
            {
                throw new \RuntimeException('Redis went away');
            }
        });

        $this->assertSame('paid answer', $gate->call(fn () => 'paid answer'));
        $this->assertSame('paid work', $gate->hold('doc', fn () => 'paid work'));
    }

    public function test_redis_outage_fails_closed_no_provider_call_is_made_without_a_permit(): void
    {
        Http::fake();
        $gate = $this->gateWith(new class extends MemoryGateStore
        {
            public function acquire(...$args): array
            {
                throw new \RuntimeException('Connection refused');
            }
        });
        $called = false;
        try {
            $gate->call(function () use (&$called) {
                $called = true;
            });
            $this->fail('Without Redis there is no permit and therefore no provider request.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Connection refused', $e->getMessage());
        }
        $this->assertFalse($called);
        Http::assertNothingSent();
    }

    public function test_qa_spend_is_recorded_and_capped_even_while_credits_are_off(): void
    {
        config(['ai_credits.enabled' => false, 'ai_credits.qa.max_cost_usd_per_workspace_per_day' => 0.01]);
        $ws = $this->workspace(0, 5);
        $user = $this->user->fresh();
        $guard = app(QaGuard::class);
        $this->assertNull($guard->admit($user)); // under the ceiling, and nothing is debited

        $document = $this->document($ws, 1000, 'Ready');
        DocumentAiRun::create(['workspace_id' => $ws->id, 'document_id' => $document->id, 'purpose' => 'document_qa', 'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5-20251001', 'estimated_cost_usd' => 0.02, 'status' => 'success', 'created_at' => now()]);
        try {
            $guard->admit($user);
            $this->fail('The daily provider-spend ceiling applies whether or not credits are enabled.');
        } catch (HttpException $e) {
            $this->assertSame(429, $e->getStatusCode());
        }
        $this->assertSame(0, OperationQuote::count());
        $this->assertSame(5, (int) $ws->credits()->value('documents_remaining'));
    }

    public function test_qa_over_http_records_the_run_with_credits_off_and_never_calls_a_provider_for_real(): void
    {
        config(['ai_credits.enabled' => false]);
        $ws = $this->workspace(0, 5);
        $document = $this->document($ws, 1000, 'Ready');
        Http::fake(['api.anthropic.com/*' => Http::response(['id' => 'msg', 'model' => 'claude-haiku-4-5-20251001', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => json_encode(['answer' => 'Revenue grew.', 'confidence' => 'high', 'cited_document_ids' => [$document->id]])]],
            'usage' => ['input_tokens' => 2000, 'output_tokens' => 50]])]);
        AiPrompt::create(['name' => 'document_qa', 'version' => 99, 'provider' => 'anthropic', 'model' => 'test-model', 'active' => true,
            'template' => 'Question: {{question}} Context: {{document_text}}']);

        try {
            app(AnthropicClient::class)->answerDocumentQuestion('What grew?', '[]', [$document->id], $document);
        } catch (\Throwable) {
            // The answer shape is irrelevant: the paid call and its usage record are the subject.
        }

        $run = DocumentAiRun::where('purpose', 'document_qa')->first();
        $this->assertNotNull($run, 'Q&A spend must be recorded even with the flag off');
        $this->assertSame([$ws->id, $document->id], [$run->workspace_id, $run->document_id]);
    }

    // ---------------------------------------------------------------- D: security and privacy

    public function test_customer_payloads_never_expose_cost_tokens_or_model_names(): void
    {
        $ws = $this->workspace(100);
        $this->subscribe($ws, 100);
        $document = $this->document($ws, 20000, 'Ready');
        $this->assertTrue($this->admit($document));
        DocumentAiRun::create(['workspace_id' => $ws->id, 'document_id' => $document->id, 'purpose' => 'entities', 'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5-20251001', 'estimated_cost_usd' => 0.1234, 'input_tokens' => 4321, 'output_tokens' => 12, 'status' => 'success',
            'operation_quote_id' => OperationQuote::firstOrFail()->id, 'created_at' => now()]);

        $bodies = [
            $this->getJson('/api/workspace/credits')->assertOk()->getContent(),
            $this->getJson('/api/workspace/billing')->assertOk()->getContent(),
            $this->getJson('/api/billing/plans')->assertOk()->getContent(),
            $this->getJson("/api/documents/{$document->id}")->assertOk()->getContent(),
            $this->getJson("/api/documents/{$document->id}/intelligence")->getContent(),
            $this->getJson('/api/documents')->assertOk()->getContent(),
        ];
        foreach ($bodies as $body) {
            foreach (['estimated_cost', 'cost_usd', 'provider_cost', 'input_tokens', 'output_tokens', 'claude-', 'haiku', 'sonnet', '0.1234', 'operation_quote', 'provider_cost_cap'] as $needle) {
                $this->assertStringNotContainsString($needle, strtolower($body), $needle);
            }
        }
    }

    public function test_confirmation_is_rate_limited(): void
    {
        $route = app('router')->getRoutes()->getByName('documents.confirm-credits');
        $this->assertContains('throttle:30,1', $route->gatherMiddleware());
        $this->assertContains('throttle:20,1', app('router')->getRoutes()->getByName('documents.reprocess')->gatherMiddleware());
    }

    public function test_transactional_mail_retries_instead_of_failing_once(): void
    {
        foreach ([new VerificationCodeNotification('123456'), new PasswordResetNotification('token', 'a@example.com')] as $notification) {
            $job = new SendQueuedNotifications(User::factory()->create(), $notification, ['mail']);
            $this->assertSame(3, $job->tries, get_class($notification));
            $this->assertSame([30, 120], $job->backoff());
        }
    }

    public function test_horizon_and_pulse_gates_read_cached_config_not_env(): void
    {
        config(['horizon.authorized_emails' => 'ops@example.com, other@example.com']);
        $this->assertTrue(Gate::forUser(User::factory()->make(['email' => 'ops@example.com']))->allows('viewHorizon'));
        $this->assertTrue(Gate::forUser(User::factory()->make(['email' => 'other@example.com']))->allows('viewPulse'));
        $this->assertFalse(Gate::forUser(User::factory()->make(['email' => 'stranger@example.com']))->allows('viewHorizon'));
        config(['horizon.authorized_emails' => '']);
        $this->assertFalse(Gate::forUser(User::factory()->make(['email' => 'ops@example.com']))->allows('viewHorizon'));
    }

    // ---------------------------------------------------------------- E: data and performance

    public function test_credit_endpoints_use_a_constant_number_of_queries_regardless_of_open_operations(): void
    {
        $ws = $this->workspace(5000);
        $this->subscribe($ws, 1000);
        $count = function (string $url): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($url)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $this->assertTrue($this->admit($this->document($ws, 1000)));
        $before = [$count('/api/workspace/credits'), $count('/api/workspace/billing')];
        for ($i = 0; $i < 12; $i++) {
            $this->assertTrue($this->admit($this->document($ws, 1000)));
        }
        $after = [$count('/api/workspace/credits'), $count('/api/workspace/billing')];

        // A warm-up difference of one query is allowed; growth with 12 more reservations is not.
        $this->assertLessThanOrEqual($before[0] + 1, $after[0], 'credits query count must not grow with reservations');
        $this->assertLessThanOrEqual($before[1] + 1, $after[1], 'billing query count must not grow with reservations');
        $this->assertLessThan(40, $after[0]);
    }

    public function test_ai_credit_migration_is_additive_defaulted_and_reversible(): void
    {
        $columns = DB::select("select table_name, column_name, is_nullable, column_default from information_schema.columns
            where (table_name = 'billing_operations' and column_name in ('quote_id','amount_reserved','amount_settled','funding_bucket','quote_version','release_reason'))
            or (table_name = 'subscription_usage_periods' and column_name in ('ai_credits_allowed','ai_credits_used'))
            or (table_name = 'workspace_credits' and column_name = 'ai_credits_remaining')
            or (table_name = 'document_ai_runs' and column_name in ('operation_quote_id','user_id','comparison_id'))");
        $this->assertCount(12, $columns);
        foreach ($columns as $column) {
            $this->assertTrue($column->is_nullable === 'YES' || $column->column_default !== null, "{$column->table_name}.{$column->column_name} would lock or fail on a populated table");
        }

        $migration = require database_path('migrations/2026_10_07_000001_add_ai_credit_accounting.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('operation_quotes'));
        $this->assertFalse(Schema::hasColumn('billing_operations', 'amount_reserved'));
        $this->assertFalse(Schema::hasColumn('workspace_credits', 'ai_credits_remaining'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('operation_quotes'));
        $this->assertTrue(Schema::hasColumn('document_ai_runs', 'operation_quote_id'));
    }
}
