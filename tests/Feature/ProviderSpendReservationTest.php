<?php

namespace Tests\Feature;

use App\Exceptions\AiProcessingException;
use App\Models\Document;
use App\Models\User;
use App\Services\AiCredits\CreditAccountant;
use App\Services\AiCredits\OperationSpend;
use App\Services\AiCredits\QuoteService;
use App\Services\AnthropicClient;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProviderSpendReservationTest extends TestCase
{
    use RefreshDatabase;

    private function quote(): array
    {
        config(['ai_credits.enabled' => true]);
        $user = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $workspace->credits()->update(['documents_remaining' => 0, 'ai_credits_remaining' => 20]);
        $document = Document::create(['name' => 'Fixture.pdf', 'type' => 'PDF', 'size_kb' => 1,
            'status' => 'Processing', 'classification' => 'Public', 'year' => 2026,
            'workspace_id' => $workspace->id, 'uploaded_by' => $user->id,
            'extracted_text' => 'Fixture text.']);
        $quote = app(QuoteService::class)->quoteDocument($document)['quote'];
        app(CreditAccountant::class)->reserve($quote, $user->id);

        return [$document, $quote];
    }

    public function test_attempt_release_and_settlement_are_idempotent_and_one_way(): void
    {
        [, $quote] = $this->quote();
        $spend = app(OperationSpend::class);
        $released = (string) Str::uuid();
        $spend->reserveAttempt($quote->id, $released, 'entities', 0.04);
        $this->assertEqualsWithDelta(0.04, $spend->spentUsd($quote->id), 0.000001);
        $spend->releaseAttempt($released);
        $spend->releaseAttempt($released);
        $spend->finishAttempt($released, 0.04);
        $this->assertSame(0.0, $spend->spentUsd($quote->id));

        $settled = (string) Str::uuid();
        $spend->reserveAttempt($quote->id, $settled, 'entities', 0.05);
        $spend->markDispatched($settled);
        $spend->finishAttempt($settled, 0.03);
        $spend->finishAttempt($settled, 0.06); // stale callback cannot rewrite actual usage
        $spend->releaseAttempt($settled);
        $this->assertEqualsWithDelta(0.03, $spend->spentUsd($quote->id), 0.000001);
        $this->assertSame('settled', DB::table('provider_call_attempts')->where('id', $settled)->value('status'));
        $this->assertSame(1, DB::table('provider_call_attempts')->where('id', $settled)->count());

        $this->expectException(AiProcessingException::class);
        $spend->reserveAttempt($quote->id, $settled, 'entities', 0.01);
    }

    public function test_ambiguous_outcome_keeps_hold_and_retry_cannot_overdraw(): void
    {
        [, $quote] = $this->quote();
        $spend = app(OperationSpend::class);
        $estimate = round($quote->provider_cost_cap_usd * 0.7, 6);
        $first = (string) Str::uuid();
        $spend->reserveAttempt($quote->id, $first, 'entities', $estimate);
        $spend->markDispatched($first);
        $spend->finishAttempt($first, null);
        $spend->releaseAttempt($first); // a timeout is never made free by a late release
        $this->assertSame('uncertain', DB::table('provider_call_attempts')->where('id', $first)->value('status'));
        $this->assertEqualsWithDelta($estimate, $spend->spentUsd($quote->id), 0.000001);

        $this->expectException(AiProcessingException::class);
        $this->expectExceptionMessage('budget_exceeded');
        $spend->reserveAttempt($quote->id, (string) Str::uuid(), 'entities', $estimate);
    }

    public function test_credit_settle_and_release_have_one_authoritative_ledger_effect(): void
    {
        [$document, $quote] = $this->quote();
        $credits = app(CreditAccountant::class);
        $this->assertTrue($credits->settle('document', $document->id));
        $this->assertFalse($credits->settle('document', $document->id));
        $this->assertFalse($credits->release('document', $document->id, 'late_failure'));
        $this->assertSame(1, DB::table('credit_ledger')->where('reference', 'ai_debit:'.$quote->id)->count());
        $this->assertSame(0, DB::table('credit_ledger')->where('reference', 'ai_release:'.$quote->id)->count());
    }

    public function test_credit_release_blocks_stale_settlement_and_releases_once(): void
    {
        [$document, $quote] = $this->quote();
        $credits = app(CreditAccountant::class);
        $this->assertTrue($credits->release('document', $document->id, 'parser_failed'));
        $this->assertFalse($credits->release('document', $document->id, 'duplicate_timeout'));
        $this->assertFalse($credits->settle('document', $document->id));
        $this->assertSame(1, DB::table('credit_ledger')->where('reference', 'ai_release:'.$quote->id)->count());
        $this->assertSame(0, DB::table('credit_ledger')->where('reference', 'ai_debit:'.$quote->id)->count());
    }

    public function test_provider_failure_after_dispatch_keeps_an_uncertain_charge(): void
    {
        [$document, $quote] = $this->quote();
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['type' => 'overloaded_error']], 503)]);
        $client = app(AnthropicClient::class)->forOperation($quote->id);
        (new \ReflectionProperty($client, 'currentOperation'))->setValue($client, 'entities');
        try {
            (new \ReflectionMethod($client, 'callWithRetry'))->invoke($client,
                [['role' => 'user', 'content' => 'Fixture text.']], 1,
                ['intelligence_document' => $document, 'typed_errors' => true, 'max_attempts' => 1]);
            $this->fail('Provider failure must not be accepted.');
        } catch (AiProcessingException $e) {
            $this->assertSame('transient', $e->classification);
        }
        Http::assertSentCount(1);
        $this->assertSame('uncertain', DB::table('provider_call_attempts')->where('operation_quote_id', $quote->id)->value('status'));
        $this->assertGreaterThan(0, app(OperationSpend::class)->spentUsd($quote->id));
    }

    public function test_late_attempt_cannot_dispatch_after_credit_release(): void
    {
        [$document, $quote] = $this->quote();
        $spend = app(OperationSpend::class);
        $attempt = (string) Str::uuid();
        $spend->reserveAttempt($quote->id, $attempt, 'entities', 0.02);
        app(CreditAccountant::class)->release('document', $document->id, 'terminal_failure');
        try {
            $spend->markDispatched($attempt);
            $this->fail('Released credit must block a stale dispatch.');
        } catch (AiProcessingException $e) {
            $this->assertSame('credits_not_reserved', $e->classification);
        }
        $spend->releaseAttempt($attempt);
        $this->assertSame('released', DB::table('provider_call_attempts')->where('id', $attempt)->value('status'));
    }

    public function test_invalid_provider_usage_cannot_make_a_dispatched_attempt_free(): void
    {
        [, $quote] = $this->quote();
        $spend = app(OperationSpend::class);
        $attempt = (string) Str::uuid();
        $spend->reserveAttempt($quote->id, $attempt, 'entities', 0.02);
        $spend->markDispatched($attempt);
        $spend->finishAttempt($attempt, -1.0);
        $this->assertSame('uncertain', DB::table('provider_call_attempts')->where('id', $attempt)->value('status'));
        $this->assertEqualsWithDelta(0.02, $spend->spentUsd($quote->id), 0.000001);
    }
}
