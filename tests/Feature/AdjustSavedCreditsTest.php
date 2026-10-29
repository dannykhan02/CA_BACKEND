<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Services\AiCredits\CreditAccountant;
use App\Services\CreditLedger;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdjustSavedCreditsTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(int $saved = 150): Workspace
    {
        $user = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $workspace->credits()->update(['documents_remaining' => 0, 'ai_credits_remaining' => $saved]);

        return $workspace;
    }

    private function saved(Workspace $workspace): int
    {
        return (int) $workspace->credits()->value('ai_credits_remaining');
    }

    private function adjustRows(Workspace $workspace)
    {
        return DB::table('credit_ledger')->where('workspace_id', $workspace->id)->where('reference', 'like', 'saved\_adjust:%')->get();
    }

    private function args(Workspace $workspace, string $delta = '-150', array $extra = []): array
    {
        return ['workspace' => $workspace->id, 'delta' => $delta, '--reason' => 'refund correction', '--run-id' => 'run-1'] + $extra;
    }

    public function test_the_dry_run_changes_nothing(): void
    {
        $workspace = $this->workspace();

        $this->artisan('credits:adjust-saved', $this->args($workspace))
            ->expectsOutputToContain('saved balance 150, delta -150, new balance 0')->expectsOutputToContain('Nothing was changed.')->assertExitCode(0);

        $this->assertSame(150, $this->saved($workspace));
        $this->assertCount(0, $this->adjustRows($workspace));
    }

    public function test_apply_takes_150_from_150_to_0_with_one_ledger_row(): void
    {
        $workspace = $this->workspace();

        $this->artisan('credits:adjust-saved', $this->args($workspace, '-150', ['--apply' => true]))->assertExitCode(0);

        $this->assertSame(0, $this->saved($workspace));
        $rows = $this->adjustRows($workspace);
        $this->assertCount(1, $rows);
        $this->assertSame('saved_adjust:'.$workspace->id.':run-1', $rows[0]->reference);
        $this->assertSame(CreditAccountant::SAVED, $rows[0]->unit);
        $this->assertSame('debit', $rows[0]->direction);
        $this->assertSame(150, (int) $rows[0]->amount);
        $this->assertSame(0, (int) $rows[0]->resulting_balance);
        $this->assertStringContainsString('refund correction', $rows[0]->reason);
    }

    public function test_a_positive_delta_is_a_credit_row(): void
    {
        $workspace = $this->workspace(10);

        $this->artisan('credits:adjust-saved', $this->args($workspace, '25', ['--apply' => true]))->assertExitCode(0);

        $this->assertSame(35, $this->saved($workspace));
        $this->assertSame('credit', $this->adjustRows($workspace)[0]->direction);
    }

    public function test_the_same_run_id_twice_changes_once(): void
    {
        $workspace = $this->workspace(300);
        $this->artisan('credits:adjust-saved', $this->args($workspace, '-100', ['--apply' => true]))->assertExitCode(0);

        $this->artisan('credits:adjust-saved', $this->args($workspace, '-100', ['--apply' => true]))
            ->expectsOutputToContain('already applied')->assertExitCode(0);

        $this->assertSame(200, $this->saved($workspace));
        $this->assertCount(1, $this->adjustRows($workspace));
    }

    public function test_a_negative_balance_is_refused_and_nothing_changes(): void
    {
        $workspace = $this->workspace(100);

        $this->artisan('credits:adjust-saved', $this->args($workspace, '-101', ['--apply' => true]))->assertExitCode(1);

        $this->assertSame(100, $this->saved($workspace));
        $this->assertCount(0, $this->adjustRows($workspace));
    }

    public function test_a_missing_reason_a_missing_run_id_a_zero_delta_and_an_unknown_workspace_are_refused(): void
    {
        $workspace = $this->workspace();
        $noReason = $this->args($workspace, '-5', ['--apply' => true]);
        unset($noReason['--reason']);
        $noRun = $this->args($workspace, '-5', ['--apply' => true]);
        unset($noRun['--run-id']);

        $this->artisan('credits:adjust-saved', $noReason)->assertExitCode(1);
        $this->artisan('credits:adjust-saved', $noRun)->assertExitCode(1);
        $this->artisan('credits:adjust-saved', $this->args($workspace, '0', ['--apply' => true]))->assertExitCode(1);
        $this->artisan('credits:adjust-saved', ['workspace' => '00000000-0000-0000-0000-000000000000'] + $this->args($workspace, '-5', ['--apply' => true]))->assertExitCode(1);

        $this->assertSame(150, $this->saved($workspace));
        $this->assertCount(0, $this->adjustRows($workspace));
    }

    public function test_the_ai_credits_status_check_stays_ok_after_an_adjustment(): void
    {
        $workspace = $this->workspace(150);
        $ledger = app(CreditLedger::class);
        $ledger->record($workspace->id, 'hold-test-reserve', CreditAccountant::SAVED, 'reserve', 10, 'test');
        $ledger->record($workspace->id, 'hold-test-release', CreditAccountant::SAVED, 'release', 10, 'test');
        $this->artisan('credits:adjust-saved', $this->args($workspace, '-150', ['--apply' => true]))->assertExitCode(0);

        Artisan::call('docintel:ai-credits-status', ['--workspace' => $workspace->id]);
        $output = Artisan::output();

        $this->assertStringContainsString($workspace->id, $output);
        $this->assertStringNotContainsString('MISMATCH', $output);
        $this->assertMatchesRegularExpression('/\|\s*ok\s*\|/', $output);
    }
}
