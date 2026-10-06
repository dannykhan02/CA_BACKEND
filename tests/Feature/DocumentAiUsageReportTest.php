<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\DocumentChunk;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DocumentAiUsageReportTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(): Document
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $text = str_repeat('CONFIDENTIAL sector narrative sentence. ', 250); // 10,000 characters.
        $document = Document::create(['workspace_id' => $user->current_workspace_id, 'uploaded_by' => $user->id,
            'name' => 'Secret Merger Plan.pdf', 'type' => 'PDF', 'status' => 'Ready', 'classification' => 'Internal',
            'size_kb' => 10, 'year' => 2026, 'pages' => 1, 'file_hash' => hash('sha256', 'usage'), 'extracted_text' => $text]);
        $document->forceFill(['ai_pipeline' => ['key' => 'k', 'route' => 'incremental', 'mode' => 'coarse', 'tokens' => 2500, 'analysis_revision' => 1]])->save();
        $chunk = fn (string $identity, string $status, ?string $failure, int $attempts, int $overlap = 0, ?string $parent = null, int $depth = 0) => DocumentChunk::create([
            'workspace_id' => $document->workspace_id, 'document_id' => $document->id, 'pipeline_key' => 'k', 'identity' => $identity,
            'input_hash' => 'h', 'pipeline_version' => '1', 'prompt_version' => '1', 'start_offset' => 0, 'end_offset' => 100,
            'status' => $status, 'failure_class' => $failure, 'attempts' => $attempts, 'overlap_chars' => $overlap,
            'parent_id' => $parent, 'depth' => $depth, 'token_count' => 1000]);
        $parent = $chunk('chunk:0', 'split', 'max_tokens', 1);
        $done = $chunk('chunk:0.0', 'completed', null, 1, 0, $parent->id, 1);
        $limit = $chunk('chunk:0.1', 'failed', 'split_limit', 1, 0, $parent->id, 1);
        $second = $chunk('chunk:1', 'completed', null, 2, 400);
        $run = fn (DocumentChunk $c, string $status, ?string $failure, int $in, int $out, float $cost, int $attempt = 1, string $model = 'claude-haiku-4-5-20251001') => DocumentAiRun::create([
            'workspace_id' => $document->workspace_id, 'document_id' => $document->id, 'purpose' => 'entities', 'provider' => 'anthropic',
            'model' => $model, 'chunk_id' => $c->id, 'request_attempt' => $attempt, 'input_tokens' => $in, 'output_tokens' => $out,
            'estimated_cost_usd' => $cost, 'status' => $status, 'failure_class' => $failure, 'created_at' => now()]);
        $run($parent, 'truncated', 'max_tokens', 1000, 4096, 0.0215);
        $run($done, 'success', null, 500, 800, 0.0045);
        $run($limit, 'truncated', 'max_tokens', 500, 4096, 0.0210);
        $run($second, 'transient', 'transient', 1000, 0, 0.001);
        $run($second, 'success', null, 1000, 900, 0.0055, 2);
        $run($second, 'success', null, 1000, 900, 0.0055, 3); // A completed leaf billed twice.
        DocumentAiRun::create(['workspace_id' => $document->workspace_id, 'document_id' => $document->id, 'purpose' => 'document_summary',
            'provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'input_tokens' => 3000, 'output_tokens' => 1000,
            'estimated_cost_usd' => 0.024, 'status' => 'success', 'created_at' => now()]);

        return $document;
    }

    public function test_report_measures_spend_amplification_outcomes_waste_retries_and_recalls(): void
    {
        $document = $this->scenario();
        $before = [DocumentAiRun::count(), DocumentChunk::count()];
        self::assertSame(0, Artisan::call('docintel:ai-usage-report', ['document' => $document->id, '--json' => true]));
        $output = Artisan::output();
        $report = json_decode($output, true);

        $byDay = collect($report['spend_by_day_model_purpose']);
        self::assertSame(6, (int) $byDay->where('purpose', 'extraction')->sum('calls'));
        self::assertSame(3000, (int) $byDay->firstWhere('model', 'claude-sonnet-4-6')['input_tokens']);

        $worst = $report['worst_documents_by_amplification'][0];
        self::assertSame($document->id, $worst['document_id']);
        self::assertSame('coarse', $worst['mode']);
        self::assertSame(1, (int) $worst['reanalyses']);
        self::assertSame(19792, (int) $worst['provider_tokens']);
        self::assertEquals(round(19792 / 2500, 2), (float) $worst['amplification']);

        $outcomes = collect($report['provider_calls_by_outcome'])->pluck('calls', 'call_outcome')->map(fn ($v) => (int) $v)->all();
        self::assertSame(['max_tokens' => 2, 'success' => 4, 'transient' => 1], $outcomes);
        $leaves = collect($report['extraction_leaves_by_outcome']);
        self::assertSame(1, (int) $leaves->firstWhere('failure_class', 'split_limit')['chunks']);
        self::assertSame(1, (int) $leaves->firstWhere('status', 'split')['chunks']);

        // Truncated split parent + split_limit leaf + the transient failure are wasted spend.
        self::assertEqualsWithDelta(0.0435, collect($report['wasted_spend'])->sum(fn ($r) => (float) $r['cost_usd']), 0.00001);
        // 400 overlap characters sent twice at 0.25 tokens/character.
        self::assertSame(800, (int) $report['overlap_resent'][0]['overlap_chars_sent']);
        self::assertSame(200, (int) $report['overlap_resent'][0]['overlap_tokens_sent']);
        self::assertSame(2, (int) $report['retry_spend'][0]['calls']);
        self::assertSame(2, (int) $report['completed_leaves_recalled'][0]['successful_calls']);

        // Metadata only, and strictly read-only.
        self::assertStringNotContainsString('CONFIDENTIAL', $output);
        self::assertStringNotContainsString('Secret Merger Plan', $output);
        self::assertSame($before, [DocumentAiRun::count(), DocumentChunk::count()]);
    }

    public function test_report_runs_in_a_read_only_transaction_and_prints_tables(): void
    {
        $this->scenario();
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });
        $this->artisan('docintel:ai-usage-report', ['--days' => 1])
            ->expectsOutputToContain('worst documents by amplification')->assertExitCode(0);
        self::assertContains('SET TRANSACTION READ ONLY', $statements);
        foreach ($statements as $sql) {
            self::assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete)\b/i', $sql);
        }
    }
}
