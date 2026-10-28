<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\OperationQuote;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AiCredits\QuoteService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** credits:reconcile-attempts closes provider attempts that stayed open, so they stop blocking actual-cost settlement. */
class ReconcileProviderAttemptsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Document $document;

    private OperationQuote $quote;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai_credits.enabled' => true]);
        $user = User::factory()->create();
        $this->workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $this->document = Document::create(['name' => 'Report.pdf', 'type' => 'PDF', 'size_kb' => 1, 'status' => 'Ready', 'classification' => 'Public',
            'year' => 2026, 'workspace_id' => $this->workspace->id, 'uploaded_by' => $user->id, 'file_hash' => hash('sha256', uniqid('', true))]);
        $this->quote = app(QuoteService::class)->issue($this->workspace->id, 'document', $this->document->id,
            ['band' => 'small', 'credits' => 2, 'cap' => 0.054, 'preflight' => ['pages' => 1]]);
    }

    private function attempt(string $status, int $minutesOld, string $purpose = 'entities', int $reserved = 40000): string
    {
        $id = (string) Str::uuid();
        DB::table('provider_call_attempts')->insert(['id' => $id, 'operation_quote_id' => $this->quote->id, 'purpose' => $purpose, 'status' => $status,
            'reserved_microusd' => $reserved, 'settled_microusd' => null, 'created_at' => now()->subMinutes($minutesOld + 1),
            'updated_at' => now()->subMinutes($minutesOld)]);

        return $id;
    }

    private function run(string $purpose, string $cost, int $minutesOld): DocumentAiRun
    {
        return DocumentAiRun::create(['workspace_id' => $this->workspace->id, 'document_id' => $this->document->id, 'file_hash' => $this->document->file_hash,
            'purpose' => $purpose, 'provider' => 'anthropic', 'model' => 'test', 'input_tokens' => 1, 'output_tokens' => 1, 'status' => 'success',
            'estimated_cost_usd' => $cost, 'operation_quote_id' => $this->quote->id, 'created_at' => now()->subMinutes($minutesOld)]);
    }

    private function row(string $id): object
    {
        return DB::table('provider_call_attempts')->where('id', $id)->firstOrFail();
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $matched = $this->attempt('dispatched', 180);
        $this->run('entities', '0.050000', 179);
        $abandoned = $this->attempt('reserved', 180, 'risks');
        $before = DB::table('provider_call_attempts')->orderBy('id')->get()->all();
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (! preg_match('/^\s*(select|with)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $this->artisan('credits:reconcile-attempts')->expectsOutputToContain('Dry run: nothing was changed')->assertSuccessful();

        self::assertSame([], $writes);
        self::assertEquals($before, DB::table('provider_call_attempts')->orderBy('id')->get()->all());
        self::assertSame('dispatched', $this->row($matched)->status);
        self::assertSame('reserved', $this->row($abandoned)->status);
        self::assertNull(DocumentAiRun::first()->provider_attempt_id);
    }

    public function test_apply_settles_a_matched_attempt_at_the_run_cost_and_links_the_run(): void
    {
        $attempt = $this->attempt('dispatched', 180);
        $run = $this->run('entities', '0.050000', 179);

        $this->artisan('credits:reconcile-attempts', ['--apply' => true])->assertSuccessful();

        $row = $this->row($attempt);
        self::assertSame('settled', $row->status);
        self::assertSame(50000, (int) $row->settled_microusd);
        self::assertSame($attempt, $run->fresh()->provider_attempt_id);
    }

    public function test_apply_resolves_unmatched_attempts(): void
    {
        $neverSent = $this->attempt('reserved', 180, 'risks');
        $lost = $this->attempt('uncertain', 180, 'deadlines', 70000);
        $this->run('entities', '0.010000', 179); // another purpose: not a match for either

        $this->artisan('credits:reconcile-attempts', ['--apply' => true])->assertSuccessful();

        self::assertSame('released', $this->row($neverSent)->status);
        self::assertSame(0, (int) $this->row($neverSent)->settled_microusd);
        self::assertSame('settled', $this->row($lost)->status);
        self::assertSame(70000, (int) $this->row($lost)->settled_microusd, 'a possibly billed call keeps counting what it held');
    }

    public function test_attempts_younger_than_the_threshold_are_untouched(): void
    {
        $young = $this->attempt('dispatched', 10);
        $this->run('entities', '0.050000', 9);
        $old = $this->attempt('reserved', 90, 'risks');

        $this->artisan('credits:reconcile-attempts', ['--apply' => true])->assertSuccessful();
        self::assertSame('dispatched', $this->row($young)->status);
        self::assertNull($this->row($young)->settled_microusd);
        self::assertSame('released', $this->row($old)->status);

        $this->artisan('credits:reconcile-attempts', ['--apply' => true, '--older-than' => 5])->assertSuccessful();
        self::assertSame('settled', $this->row($young)->status);
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->attempt('dispatched', 180);
        $this->run('entities', '0.050000', 179);
        $this->attempt('uncertain', 180, 'risks');
        $this->artisan('credits:reconcile-attempts', ['--apply' => true])->assertSuccessful();
        $after = DB::table('provider_call_attempts')->orderBy('id')->get()->all();

        $this->artisan('credits:reconcile-attempts', ['--apply' => true])->expectsOutputToContain('0 open attempt(s)')->assertSuccessful();

        self::assertEquals($after, DB::table('provider_call_attempts')->orderBy('id')->get()->all());
    }
}
