<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\OperationQuote;
use App\Models\User;
use App\Services\AiCredits\CreditAccountant;
use App\Services\AiCredits\OperationSpend;
use App\Services\AiCredits\QuoteService;
use App\Services\WorkspaceCreditService;
use App\Services\WorkspaceService;
use Database\Seeders\DocumentSummaryPromptSeederV3;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** A quote made from the local estimate keeps its credits when the provider count says the document is bigger. */
class UnderQuotedDocumentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DocumentSummaryPromptSeederV3::class);
        config(['ai_credits.enabled' => true, 'document_intelligence.incremental' => true]);
    }

    /** 90,000 bytes: a local estimate of 30,000 tokens x 1.15 = 34,500, the large band (20 credits, cap 0.54). */
    private function quoted(): array
    {
        $this->user = User::factory()->create();
        $ws = app(WorkspaceService::class)->createPersonalWorkspaceFor($this->user);
        $ws->credits()->update(['documents_remaining' => 0, 'ai_credits_remaining' => 100]);
        $document = Document::create(['name' => 'Report.pdf', 'type' => 'PDF', 'size_kb' => 1, 'status' => 'Processing', 'classification' => 'Public', 'year' => 2026,
            'workspace_id' => $ws->id, 'uploaded_by' => $this->user->id, 'extracted_text' => substr(str_repeat('Revenue grew in the quarter. ', 3100), 0, 90000)]);
        $quote = app(QuoteService::class)->quoteDocument($document)['quote'];
        $this->assertSame(['large', 20], [$quote->band, $quote->credits]);
        app(CreditAccountant::class)->reserve($quote, $this->user->id);

        return [$ws, $document, $quote];
    }

    private function providerCount(Document $document, int $tokens): Document
    {
        $document->forceFill(['ai_pipeline' => ['count_method' => 'anthropic', 'tokens' => $tokens]])->save();

        return $document->fresh();
    }

    public function test_a_higher_provider_band_raises_the_cap_but_not_the_charge_and_logs_once(): void
    {
        [$ws, $document, $quote] = $this->quoted();
        $document = $this->providerCount($document, 54120); // xlarge: cap 0.945
        Log::spy();
        $quotes = app(QuoteService::class);

        $raised = $quotes->raiseCapForProviderCount($quote->fresh(), $document);
        $quotes->raiseCapForProviderCount($raised, $document); // a second call changes nothing and logs nothing

        $this->assertSame(['large', 20], [$raised->band, $raised->credits], 'the quoted band and credits stand');
        $this->assertEqualsWithDelta(0.945, $raised->provider_cost_cap_usd, 1e-6);
        $this->assertEqualsWithDelta(0.945, app(OperationSpend::class)->remainingUsd($raised), 1e-6, 'the document may spend up to the higher cap');
        Log::shouldHaveReceived('info')->once()->with('Quote band raised by provider count', \Mockery::on(fn ($c) => $c['document_id'] === $document->id
            && $c['quoted_band'] === 'large' && $c['quoted_credits'] === 20 && $c['provider_count_band'] === 'xlarge'
            && $c['tokens'] === 54120 && abs($c['cap_usd'] - 0.945) < 1e-6));

        app(WorkspaceCreditService::class)->accountForReadyDocument($document->fresh());
        $operation = DB::table('billing_operations')->where('kind', 'document')->where('resource_id', $document->id)->first();
        $this->assertSame([20, 'completed'], [(int) $operation->amount_settled, $operation->status], 'the customer is charged the quoted 20 credits');
    }

    public function test_the_cap_is_left_alone_when_the_provider_count_is_in_the_same_band_or_absent(): void
    {
        [, $document, $quote] = $this->quoted();
        Log::spy();
        $quotes = app(QuoteService::class);

        $same = $quotes->raiseCapForProviderCount($quote->fresh(), $this->providerCount($document, 31000));
        $this->assertEqualsWithDelta(0.54, $same->provider_cost_cap_usd, 1e-6);

        $absent = $quotes->raiseCapForProviderCount($quote->fresh(), $document->fresh()->forceFill(['ai_pipeline' => ['count_method' => 'byte_upper_bound', 'tokens' => 90000]]));
        $this->assertEqualsWithDelta(0.54, $absent->provider_cost_cap_usd, 1e-6);
        Log::shouldNotHaveReceived('info', ['Quote band raised by provider count', \Mockery::any()]);
        $this->assertSame(1, OperationQuote::count());
    }
}
