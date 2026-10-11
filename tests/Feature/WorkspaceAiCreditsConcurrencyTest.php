<?php

namespace Tests\Feature;

use App\Exceptions\AiProcessingException;
use App\Models\Document;
use App\Models\OperationQuote;
use App\Models\User;
use App\Models\WorkspaceCredit;
use App\Services\AiCredits\CreditAccountant;
use App\Services\AiCredits\OperationSpend;
use App\Services\AiCredits\QuoteService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Independent worker processes racing for the same balance. */
class WorkspaceAiCreditsConcurrencyTest extends TestCase
{
    // Fixtures must commit so independent worker connections can see them.
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        // These fixtures are committed for the workers; never leave them behind for RefreshDatabase tests.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('TRUNCATE TABLE users, workspaces RESTART IDENTITY CASCADE');
        }
        parent::tearDown();
    }

    private function fixture(int $saved): array
    {
        config(['ai_credits.enabled' => true]);
        $user = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $workspace->credits()->update(['documents_remaining' => 0, 'ai_credits_remaining' => $saved]);

        return [$user, $workspace];
    }

    private function document($workspace, User $user): Document
    {
        return Document::create(['name' => 'Race.pdf', 'type' => 'PDF', 'size_kb' => 1, 'status' => 'Processing', 'classification' => 'Public',
            'year' => 2026, 'workspace_id' => $workspace->id, 'uploaded_by' => $user->id, 'extracted_text' => str_repeat('Revenue grew in the quarter. ', 2000)]);
    }

    /** @param  array<int, callable(): void>  $jobs  each runs in its own process: exit 0 = succeeded, 2 = refused with 402 */
    private function race(array $jobs): array
    {
        if (! function_exists('pcntl_fork') || DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL and pcntl for independent worker processes.');
        }
        $directory = sys_get_temp_dir().'/ai-credit-race-'.bin2hex(random_bytes(8));
        mkdir($directory);
        DB::disconnect(); // never inherit an open PDO socket into a worker
        $children = [];
        foreach ($jobs as $index => $job) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                try {
                    $job();
                    exit(0);
                } catch (HttpException $e) {
                    exit($e->getStatusCode() === 402 ? 2 : 1);
                } catch (AiProcessingException $e) {
                    exit($e->getMessage() === 'AI operation stopped: budget_exceeded' ? 2 : 1);
                } catch (\Throwable $e) {
                    file_put_contents($directory.'/error-'.$index, $e->getMessage());
                    exit(1);
                }
            }
            $children[] = $pid;
        }
        $codes = [];
        try {
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $errors = array_map('file_get_contents', glob($directory.'/error-*'));
                $this->assertTrue(pcntl_wifexited($status) && in_array(pcntl_wexitstatus($status), [0, 2], true), implode("\n", $errors));
                $codes[] = pcntl_wexitstatus($status);
            }
        } finally {
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
        sort($codes);

        return $codes;
    }

    public function test_two_uploads_cannot_overdraw_one_balance(): void
    {
        [$user, $workspace] = $this->fixture(10);
        $quotes = [];
        foreach ([1, 2] as $_) {
            $quotes[] = app(QuoteService::class)->quoteDocument($this->document($workspace, $user))['quote']->id;
        }
        $this->assertSame([10, 10], OperationQuote::pluck('credits')->all());

        $codes = $this->race(array_map(fn ($id) => function () use ($id, $user) {
            app(CreditAccountant::class)->reserve(OperationQuote::findOrFail($id), $user->id);
        }, $quotes));

        $this->assertSame([0, 2], $codes); // exactly one reserved, the other refused
        $this->assertSame(1, DB::table('billing_operations')->where('status', 'reserved')->count());
        $this->assertSame(10, (int) DB::table('billing_operations')->where('status', 'reserved')->sum('amount_reserved'));
        $this->assertSame(10, (int) WorkspaceCredit::firstOrFail()->ai_credits_remaining);
    }

    public function test_an_upload_and_a_comparison_race_for_the_same_balance(): void
    {
        [$user, $workspace] = $this->fixture(12);
        $docQuote = app(QuoteService::class)->quoteDocument($this->document($workspace, $user))['quote']->id;
        $cmpQuote = app(QuoteService::class)->quoteComparison($workspace->id, (string) Str::uuid())->id;

        $codes = $this->race(array_map(fn ($id) => function () use ($id, $user) {
            app(CreditAccountant::class)->reserve(OperationQuote::findOrFail($id), $user->id);
        }, [$docQuote, $cmpQuote]));

        $this->assertSame([0, 2], $codes);
        $this->assertLessThanOrEqual(12, (int) DB::table('billing_operations')->where('status', 'reserved')->sum('amount_reserved'));
    }

    public function test_two_workers_settling_the_same_document_debit_once_and_never_go_negative(): void
    {
        [$user, $workspace] = $this->fixture(10);
        $document = $this->document($workspace, $user);
        app(CreditAccountant::class)->reserve(app(QuoteService::class)->quoteDocument($document)['quote'], $user->id);

        $codes = $this->race(array_fill(0, 3, function () use ($document) {
            app(CreditAccountant::class)->settle('document', $document->id);
        }));

        $this->assertSame([0, 0, 0], $codes);
        $this->assertSame(0, (int) WorkspaceCredit::firstOrFail()->ai_credits_remaining);
        $this->assertSame(1, DB::table('credit_ledger')->where('direction', 'debit')->count());
        $this->assertSame(10, (int) DB::table('billing_operations')->value('amount_settled'));
    }

    public function test_settle_and_release_racing_on_one_operation_resolve_to_exactly_one_outcome(): void
    {
        [$user, $workspace] = $this->fixture(10);
        $document = $this->document($workspace, $user);
        app(CreditAccountant::class)->reserve(app(QuoteService::class)->quoteDocument($document)['quote'], $user->id);

        $this->race([
            fn () => app(CreditAccountant::class)->settle('document', $document->id),
            fn () => app(CreditAccountant::class)->release('document', $document->id, 'worker_failed'),
        ]);

        $status = DB::table('billing_operations')->value('status');
        $this->assertContains($status, ['completed', 'released']);
        $debits = DB::table('credit_ledger')->where('direction', 'debit')->count();
        $releases = DB::table('credit_ledger')->where('direction', 'release')->count();
        $this->assertSame(1, $debits + $releases);
        $this->assertSame($status === 'completed' ? 0 : 10, (int) WorkspaceCredit::firstOrFail()->ai_credits_remaining);
    }

    public function test_provider_attempts_compete_under_the_same_quote_lock(): void
    {
        [$user, $workspace] = $this->fixture(10);
        $document = $this->document($workspace, $user);
        $quote = app(QuoteService::class)->quoteDocument($document)['quote'];
        app(CreditAccountant::class)->reserve($quote, $user->id);
        $estimate = round($quote->provider_cost_cap_usd * 0.7, 6);

        $codes = $this->race(array_map(fn () => function () use ($quote, $estimate) {
            app(OperationSpend::class)->reserveAttempt($quote->id, (string) Str::uuid(), 'entities', $estimate);
        }, [1, 2]));

        $this->assertSame([0, 2], $codes);
        $this->assertSame(1, DB::table('provider_call_attempts')->where('status', 'reserved')->count());
        $this->assertLessThanOrEqual($quote->provider_cost_cap_usd,
            app(OperationSpend::class)->spentUsd($quote->id));
    }

    public function test_provider_attempts_both_pass_when_quote_has_room(): void
    {
        [$user, $workspace] = $this->fixture(10);
        $quote = app(QuoteService::class)->quoteDocument($this->document($workspace, $user))['quote'];
        app(CreditAccountant::class)->reserve($quote, $user->id);
        $estimate = round($quote->provider_cost_cap_usd * 0.3, 6);

        $codes = $this->race(array_map(fn () => function () use ($quote, $estimate) {
            app(OperationSpend::class)->reserveAttempt($quote->id, (string) Str::uuid(), 'entities', $estimate);
        }, [1, 2]));

        $this->assertSame([0, 0], $codes);
        $this->assertSame(2, DB::table('provider_call_attempts')->where('status', 'reserved')->count());
    }

    public function test_dispatched_attempt_settlement_racing_with_release_keeps_one_charge(): void
    {
        [$user, $workspace] = $this->fixture(10);
        $quote = app(QuoteService::class)->quoteDocument($this->document($workspace, $user))['quote'];
        app(CreditAccountant::class)->reserve($quote, $user->id);
        $attempt = (string) Str::uuid();
        $spend = app(OperationSpend::class);
        $spend->reserveAttempt($quote->id, $attempt, 'entities', 0.05);
        $spend->markDispatched($attempt);

        $this->race([
            fn () => app(OperationSpend::class)->finishAttempt($attempt, 0.03),
            fn () => app(OperationSpend::class)->releaseAttempt($attempt),
        ]);

        $this->assertSame('settled', DB::table('provider_call_attempts')->where('id', $attempt)->value('status'));
        $this->assertEqualsWithDelta(0.03, $spend->spentUsd($quote->id), 0.000001);
    }

    public function test_unquoted_attempts_compete_atomically_under_workspace_lock(): void
    {
        [$user, $workspace] = $this->fixture(0);
        config(['ai_credits.enabled' => false, 'document_intelligence.budget_max_usd' => 0.10]);
        $document = $this->document($workspace, $user);
        $scope = app(OperationSpend::class)->internalScope($document, 'entities', null, null);

        $codes = $this->race(array_map(fn () => function () use ($scope) {
            app(OperationSpend::class)->reserveInternalAttempt($scope, (string) Str::uuid(), 'entities', 0.07);
        }, [1, 2]));

        $this->assertSame([0, 2], $codes);
        $this->assertSame(1, DB::table('provider_call_attempts')->where('scope_key', $scope['key'])->count());
        $this->assertSame(0, DB::table('operation_quotes')->count());
        $this->assertSame(0, DB::table('credit_ledger')->count());
    }

    public function test_unquoted_attempts_both_pass_when_cumulative_budget_has_room(): void
    {
        [$user, $workspace] = $this->fixture(0);
        config(['ai_credits.enabled' => false, 'document_intelligence.budget_max_usd' => 0.10]);
        $document = $this->document($workspace, $user);
        $scope = app(OperationSpend::class)->internalScope($document, 'entities', null, null);

        $codes = $this->race(array_map(fn () => function () use ($scope) {
            app(OperationSpend::class)->reserveInternalAttempt($scope, (string) Str::uuid(), 'entities', 0.03);
        }, [1, 2]));

        $this->assertSame([0, 0], $codes);
        $this->assertSame(2, DB::table('provider_call_attempts')->where('scope_key', $scope['key'])->count());
        $this->assertSame(0, DB::table('credit_ledger')->count());
    }
}
