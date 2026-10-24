<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AiCredits\CreditAccountant;
use App\Services\AiCredits\QuoteService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** docintel:ai-credits-status (read-only) and docintel:grant-ai-credits. */
class AiCreditsOpsCommandsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai_credits.enabled' => true]);
    }

    private function workspace(int $savedAi = 100): Workspace
    {
        $this->user = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($this->user);
        $workspace->credits()->update(['documents_remaining' => 0, 'ai_credits_remaining' => $savedAi]);

        return $workspace;
    }

    private function hold(Workspace $ws): Document
    {
        $document = Document::create(['name' => 'R.pdf', 'type' => 'PDF', 'size_kb' => 1, 'status' => 'Processing', 'classification' => 'Public', 'year' => 2026,
            'workspace_id' => $ws->id, 'uploaded_by' => $this->user->id, 'extracted_text' => str_repeat('Revenue grew. ', 100)]);
        $quote = app(QuoteService::class)->quoteDocument($document)['quote'];
        app(CreditAccountant::class)->reserve($quote, $this->user->id);

        return $document;
    }

    private function statusOutput(array $options = []): string
    {
        Artisan::call('docintel:ai-credits-status', $options);

        return Artisan::output();
    }

    public function test_status_reconciles_a_workspace_flags_a_mismatch_and_names_deprecated_variables_without_values(): void
    {
        $ws = $this->workspace();
        $this->hold($ws);
        putenv('AI_CREDITS_SIMPLE_CREDITS=777');
        $_ENV['AI_CREDITS_SIMPLE_CREDITS'] = '777';
        putenv('AI_CREDITS_ADMISSION_MODE=realistic');
        $_ENV['AI_CREDITS_ADMISSION_MODE'] = 'realistic';
        $ledgerBefore = DB::table('credit_ledger')->count();
        $opsBefore = DB::table('billing_operations')->count();

        try {
            $ok = $this->statusOutput(['--workspace' => $ws->id]);
            DB::table('credit_ledger')->insert(['workspace_id' => $ws->id, 'unit' => 'saved_ai_credit', 'direction' => 'debit', 'amount' => 5,
                'reason' => 'test', 'reference' => 'test:stray-debit', 'created_at' => now()]);
            $bad = $this->statusOutput(['--workspace' => $ws->id]);
        } finally {
            putenv('AI_CREDITS_SIMPLE_CREDITS');
            putenv('AI_CREDITS_ADMISSION_MODE');
            unset($_ENV['AI_CREDITS_SIMPLE_CREDITS'], $_ENV['AI_CREDITS_ADMISSION_MODE']);
        }

        $this->assertStringContainsString('AI credits: ON (config)', $ok);
        $this->assertStringContainsString('Admission: realistic', $ok);
        $this->assertStringContainsString($ws->id, $ok);
        $this->assertStringContainsString('very_large', $ok);
        $this->assertStringContainsString('starter', $ok);
        $this->assertStringNotContainsString('MISMATCH', $ok);
        $this->assertMatchesRegularExpression('/\|\s*2\s*\|\s*0\s*\|\s*0\s*\|\s*2\s*\|\s*ok\s*\|/', $ok, 'reserved 2, debited 0, released 0, open 2');
        $this->assertMatchesRegularExpression('/Deprecated[^\n]*AI_CREDITS_SIMPLE_CREDITS/', $ok);
        $this->assertDoesNotMatchRegularExpression('/Deprecated[^\n]*AI_CREDITS_ADMISSION_MODE/', $ok, 'a variable the config reads is not deprecated');
        $this->assertStringNotContainsString('777', $ok);
        $this->assertStringContainsString('MISMATCH', $bad);
        $this->assertSame($ledgerBefore, DB::table('credit_ledger')->where('reference', '!=', 'test:stray-debit')->count());
        $this->assertSame($opsBefore, DB::table('billing_operations')->count());
    }

    public function test_grant_requires_a_reason_a_whole_amount_and_applies_once_per_run_id(): void
    {
        $ws = $this->workspace(10);
        $balance = fn () => (int) $ws->credits()->value('ai_credits_remaining');

        $this->assertSame(1, Artisan::call('docintel:grant-ai-credits', ['workspace' => $ws->id, 'amount' => '50']));
        $this->assertSame(1, Artisan::call('docintel:grant-ai-credits', ['workspace' => $ws->id, 'amount' => '-5', '--reason' => 'x']));
        $this->assertSame(1, Artisan::call('docintel:grant-ai-credits', ['workspace' => $ws->id, 'amount' => '1.5', '--reason' => 'x']));
        $this->assertSame(1, Artisan::call('docintel:grant-ai-credits', ['workspace' => '00000000-0000-0000-0000-000000000000', 'amount' => '5', '--reason' => 'x']));
        $this->assertSame(10, $balance());

        $args = ['workspace' => $ws->id, 'amount' => '50', '--reason' => 'support goodwill', '--run-id' => 'run-1'];
        $this->assertSame(0, Artisan::call('docintel:grant-ai-credits', $args));
        $this->assertSame(60, $balance());
        $entry = DB::table('credit_ledger')->where('reference', 'admin_grant:run-1')->first();
        $this->assertSame(['credit', 50, 60], [$entry->direction, (int) $entry->amount, (int) $entry->resulting_balance]);
        $this->assertStringContainsString('support goodwill', $entry->reason);

        $this->assertSame(0, Artisan::call('docintel:grant-ai-credits', $args));
        $this->assertStringContainsString('already applied', Artisan::output());
        $this->assertSame(60, $balance());
        $this->assertSame(1, DB::table('credit_ledger')->where('reference', 'admin_grant:run-1')->count());
    }
}
