<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\OperationQuote;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AiCredits\CreditAccountant;
use App\Services\AiCredits\DocumentCredits;
use App\Services\AiCredits\QuoteService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The `credits` object on the document payloads (docs/AI_CREDITS.md, "Per-document credits payload"). */
class DocumentCreditsPayloadTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($this->user);
        $this->workspace->credits()->update(['documents_remaining' => 0, 'ai_credits_remaining' => 500]);
        Sanctum::actingAs($this->user->fresh());
    }

    private function document(string $status = 'Processing'): Document
    {
        return Document::create(['name' => 'R-'.uniqid().'.pdf', 'type' => 'PDF', 'size_kb' => 1, 'status' => $status, 'classification' => 'Public',
            'year' => 2026, 'workspace_id' => $this->workspace->id, 'uploaded_by' => $this->user->id, 'file_hash' => hash('sha256', uniqid('', true))]);
    }

    private function quote(Document $document, int $credits = 2, string $band = 'small'): OperationQuote
    {
        return app(QuoteService::class)->issue($this->workspace->id, 'document', $document->id,
            ['band' => $band, 'credits' => $credits, 'cap' => $credits * 0.027, 'preflight' => ['pages' => 3]]);
    }

    /** The payload as a fresh request would build it (the lookup is scoped to a request). */
    private function payload(Document $document): ?array
    {
        app()->forgetScopedInstances();

        return app(DocumentCredits::class)->for($document->fresh());
    }

    public function test_each_state_maps_from_the_latest_document_quote(): void
    {
        config(['ai_credits.enabled' => true]);
        $accountant = app(CreditAccountant::class);

        $this->assertNull($this->payload($this->document()), 'no quote yet');

        $quoted = $this->document();
        $this->quote($quoted);
        $this->assertSame(['quoted' => 2, 'state' => 'quoted', 'charged' => null, 'requiresConfirmation' => false], $this->payload($quoted));

        $big = $this->document();
        $this->quote($big, 35, 'xlarge');
        $this->assertSame(['quoted' => 35, 'state' => 'awaiting_confirmation', 'charged' => null, 'requiresConfirmation' => true], $this->payload($big));
        $big->forceFill(['ai_pipeline' => ['credit_quote_confirmed' => true]])->save();
        $this->assertSame(['quoted' => 35, 'state' => 'quoted', 'charged' => null, 'requiresConfirmation' => false], $this->payload($big));

        $reserved = $this->document();
        $accountant->reserve($this->quote($reserved), $this->user->id);
        $this->assertSame(['quoted' => 2, 'state' => 'reserved', 'charged' => null, 'requiresConfirmation' => false], $this->payload($reserved));

        $charged = $this->document('Ready');
        $accountant->reserve($this->quote($charged), $this->user->id);
        $accountant->settle('document', $charged->id);
        $this->assertSame(['quoted' => 2, 'state' => 'charged', 'charged' => 2, 'requiresConfirmation' => false], $this->payload($charged));

        $released = $this->document();
        $accountant->reserve($this->quote($released), $this->user->id);
        $accountant->release('document', $released->id, 'test');
        $this->assertSame(['quoted' => 2, 'state' => 'not_charged', 'charged' => null, 'requiresConfirmation' => false], $this->payload($released));

        $partial = $this->document('Needs Review'); // the hold is released a moment later; the document already was not delivered
        $accountant->reserve($this->quote($partial), $this->user->id);
        $this->assertSame('not_charged', $this->payload($partial)['state']);
        $failed = $this->document('Failed');
        $this->quote($failed);
        $this->assertSame('not_charged', $this->payload($failed)['state']);
    }

    public function test_a_new_attempt_replaces_an_older_quote(): void
    {
        config(['ai_credits.enabled' => true]);
        $document = $this->document();
        $first = $this->quote($document);
        app(CreditAccountant::class)->reserve($first, $this->user->id);
        app(CreditAccountant::class)->release('document', $document->id, 'test');
        $this->assertSame('not_charged', $this->payload($document)['state']);

        $this->quote($document, 6, 'medium'); // the next attempt after a release
        $this->assertSame(['quoted' => 6, 'state' => 'quoted'], array_intersect_key($this->payload($document), ['quoted' => 1, 'state' => 1]));
    }

    public function test_flag_off_adds_no_key_and_no_query(): void
    {
        config(['ai_credits.enabled' => false]);
        $document = $this->document();
        $this->quote($document);

        DB::enableQueryLog();
        $detail = $this->getJson("/api/documents/{$document->id}")->assertOk()->json('data');
        $list = $this->getJson('/api/documents')->assertOk()->json('data.0');
        $quoteQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'operation_quotes'))->count();

        $this->assertArrayNotHasKey('credits', $detail);
        $this->assertArrayNotHasKey('credits', $list);
        $this->assertSame(0, $quoteQueries);
    }

    public function test_detail_and_list_carry_the_object_and_the_list_costs_one_quote_query_whatever_its_size(): void
    {
        config(['ai_credits.enabled' => true]);
        $first = $this->document();
        $this->quote($first);
        $this->document(); // no quote: null
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $data = $this->getJson('/api/documents')->assertOk()->json('data');

            return [collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'operation_quotes'))->count(), $data];
        };

        [$few, $data] = $count();
        $byId = collect($data)->keyBy('id');
        $this->assertSame('quoted', $byId[$first->id]['credits']['state']);
        $this->assertSame(2, $byId[$first->id]['credits']['quoted']);
        $this->assertSame(1, collect($data)->whereNull('credits')->count());

        foreach (range(1, 8) as $ignored) {
            $this->quote($this->document());
        }
        [$many, $data] = $count();
        $this->assertSame([1, 1], [$few, $many]);
        $this->assertCount(10, $data);
        $this->assertSame('quoted', $this->getJson("/api/documents/{$first->id}")->assertOk()->json('data.credits.state'));
    }

    public function test_upload_carries_the_object_once_a_quote_exists_and_null_before_it(): void
    {
        config(['ai_credits.enabled' => true]);
        Queue::fake();
        Storage::fake('documents');
        $file = fn () => UploadedFile::fake()->create('report.pdf', 1, 'application/pdf');

        // The quote is made when processing starts (queued), after the upload has answered: null until then.
        $created = $this->postJson('/api/documents', ['file' => $file()])->assertAccepted();
        $this->assertArrayHasKey('credits', $created->json('data'));
        $this->assertNull($created->json('data.credits'));

        $this->quote(Document::findOrFail($created->json('data.id')));
        app()->forgetScopedInstances();
        $again = $this->postJson('/api/documents', ['file' => $file()])->assertStatus(409);
        $this->assertSame('quoted', $again->json('data.credits.state'));

        config(['ai_credits.enabled' => false]);
        app()->forgetScopedInstances();
        $this->assertArrayNotHasKey('credits', $this->getJson('/api/documents/'.$created->json('data.id'))->assertOk()->json('data'));
    }
}
