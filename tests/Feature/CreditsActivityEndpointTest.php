<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AiCredits\CreditAccountant;
use App\Services\CreditLedger;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CreditsActivityEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai_credits.enabled' => true]);
        $this->workspace = $this->makeWorkspace();
    }

    private function makeWorkspace(bool $act = true): Workspace
    {
        $user = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        if ($act) {
            $this->user = $user->fresh();
            Sanctum::actingAs($this->user);
        }

        return $workspace;
    }

    private function row(Workspace $workspace, string $reference, string $unit, string $direction, int $amount, ?string $relatedType = null, ?string $relatedId = null, string $reason = 'internal operator reason'): void
    {
        app(CreditLedger::class)->record($workspace->id, $reference, $unit, $direction, $amount, $reason, $relatedType, $relatedId);
    }

    private function document(Workspace $workspace, string $name = 'LTR-2026-04417_digital.pdf'): Document
    {
        return Document::create(['name' => $name, 'type' => 'PDF', 'size_kb' => 1, 'status' => 'Ready', 'classification' => 'Public', 'year' => 2026,
            'workspace_id' => $workspace->id, 'uploaded_by' => $this->user->id, 'file_hash' => hash('sha256', uniqid('', true))]);
    }

    private function activity(string $query = ''): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/credits/activity'.$query);
    }

    public function test_sources_sum_only_incoming_rows_by_source(): void
    {
        $saved = CreditAccountant::SAVED;
        $this->row($this->workspace, 'referral:r1', $saved, 'credit', 30);
        $this->row($this->workspace, 'trial_carryover:'.$this->workspace->id, $saved, 'credit', 9);
        $this->row($this->workspace, 'admin_grant:g1', $saved, 'credit', 50);
        $this->row($this->workspace, 'saved_adjust:'.$this->workspace->id.':a1', $saved, 'credit', 10);
        $this->row($this->workspace, 'saved_adjust:'.$this->workspace->id.':a2', $saved, 'debit', 40);
        $this->row($this->workspace, 'ai_debit:q1', $saved, 'debit', 5, 'document', 'x');

        $sources = $this->activity()->assertOk()->json('data.saved_sources');

        $this->assertSame([['source' => 'referral', 'credits' => 30], ['source' => 'trial_carryover', 'credits' => 9], ['source' => 'bonus', 'credits' => 60]], $sources);
    }

    public function test_recent_is_newest_first_with_types_signs_and_buckets_and_hides_legacy_rows(): void
    {
        $document = $this->document($this->workspace);
        $saved = CreditAccountant::SAVED;
        $this->travelTo(now()->subMinutes(10));
        $this->row($this->workspace, 'ai_debit:q1', 'subscription_ai_credit', 'debit', 2, 'document', $document->id);
        $this->travelTo(now()->addMinute());
        $this->row($this->workspace, 'ai_release_unused:q1', 'subscription_ai_credit', 'release', 4, 'document', $document->id);
        $this->travelTo(now()->addMinute());
        $this->row($this->workspace, 'referral:r1', $saved, 'credit', 30, 'referral', 'secret-referral-id');
        $this->travelTo(now()->addMinute());
        $this->row($this->workspace, 'trial_expire:w:1:1', CreditAccountant::TRIAL, 'debit', 3);
        $this->travelTo(now()->addMinute());
        $this->row($this->workspace, 'saved_adjust:w:a2', $saved, 'debit', 7);
        $this->travelTo(now()->addMinute());
        $this->row($this->workspace, 'ai_convert_out:q9', 'saved_document', 'debit', 1);
        $this->row($this->workspace, 'document_debit:d1', 'saved_document', 'debit', 1);
        $this->row($this->workspace, 'ai_convert_in:q9', $saved, 'credit', 10);
        $this->row($this->workspace, 'ai_reserve:q1', $saved, 'reserve', 6, 'document', $document->id);

        $recent = $this->activity()->assertOk()->json('data.recent');

        $this->assertSame(['adjusted', 'trial_expired', 'referral', 'returned', 'charged'], array_column($recent, 'type'));
        $this->assertSame([-7, -3, 30, 4, -2], array_column($recent, 'credits'));
        $this->assertSame(['saved', 'trial', 'saved', 'monthly', 'monthly'], array_column($recent, 'bucket'));
        $this->assertSame([null, null, null, $document->id, $document->id], array_column($recent, 'document_id'));
    }

    public function test_the_limit_is_respected_and_validated(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->row($this->workspace, 'referral:r'.$i, CreditAccountant::SAVED, 'credit', 1);
        }

        $this->assertCount(10, $this->activity()->assertOk()->json('data.recent'));
        $this->assertCount(3, $this->activity('?limit=3')->assertOk()->json('data.recent'));
        $this->assertCount(12, $this->activity('?limit=50')->assertOk()->json('data.recent'));
        $this->activity('?limit=0')->assertStatus(422);
        $this->activity('?limit=51')->assertStatus(422);
    }

    public function test_document_names_come_from_own_documents_and_deleted_ones_are_null(): void
    {
        $own = $this->document($this->workspace, 'Own.pdf');
        $gone = $this->document($this->workspace, 'Gone.pdf');
        $this->row($this->workspace, 'ai_debit:q1', CreditAccountant::SAVED, 'debit', 2, 'document', $own->id);
        $this->row($this->workspace, 'ai_debit:q2', CreditAccountant::SAVED, 'debit', 3, 'document', $gone->id);
        $gone->delete();

        $byCredits = collect($this->activity()->assertOk()->json('data.recent'))->keyBy('credits');

        $this->assertSame('Own.pdf', $byCredits[-2]['document_name']);
        $this->assertNull($byCredits[-3]['document_name']);
        $this->assertSame($gone->id, $byCredits[-3]['document_id']);
    }

    public function test_another_workspaces_rows_never_appear(): void
    {
        $other = $this->makeWorkspace(false);
        $this->row($other, 'referral:other', CreditAccountant::SAVED, 'credit', 99);
        $this->row($this->workspace, 'referral:mine', CreditAccountant::SAVED, 'credit', 1);

        $data = $this->activity()->assertOk()->json('data');

        $this->assertSame([['source' => 'referral', 'credits' => 1]], $data['saved_sources']);
        $this->assertSame([1], array_column($data['recent'], 'credits'));
    }

    public function test_the_response_has_no_reason_and_no_internal_ids(): void
    {
        $document = $this->document($this->workspace);
        $this->row($this->workspace, 'ai_debit:quote-secret-1', CreditAccountant::SAVED, 'debit', 2, 'document', $document->id, 'operator says refund');
        $this->row($this->workspace, 'saved_adjust:w:a1', CreditAccountant::SAVED, 'debit', 4, 'workspace', 'ws-secret', 'operator reason text');
        $this->row($this->workspace, 'referral:ref-secret', CreditAccountant::SAVED, 'credit', 4, 'referral', 'referral-secret-id');

        $json = $this->activity()->assertOk()->getContent();

        foreach (['reason', 'operator', 'quote-secret-1', 'ws-secret', 'referral-secret-id', 'ref-secret', 'reference', 'quote_id', 'user_id', $this->user->email] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
        $row = $this->activity()->json('data.recent.0');
        ksort($row);
        $this->assertSame(['at', 'bucket', 'credits', 'document_id', 'document_name', 'id', 'type'], array_keys($row));
    }

    public function test_an_open_hold_lists_its_document_and_credits(): void
    {
        $document = $this->document($this->workspace, 'Held.pdf');
        $now = now();
        DB::table('billing_operations')->insert(['kind' => 'document', 'resource_id' => $document->id, 'workspace_id' => $this->workspace->id, 'status' => 'reserved',
            'attempt_number' => 1, 'amount_reserved' => 6, 'funding_bucket' => CreditAccountant::SAVED, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('billing_operations')->insert(['kind' => 'document', 'resource_id' => '00000000-0000-0000-0000-0000000000aa', 'workspace_id' => $this->workspace->id,
            'status' => 'settled', 'attempt_number' => 1, 'amount_reserved' => 9, 'funding_bucket' => CreditAccountant::SAVED, 'created_at' => $now, 'updated_at' => $now]);

        $holds = $this->activity()->assertOk()->json('data.holds');

        $this->assertSame([['document_id' => $document->id, 'document_name' => 'Held.pdf', 'credits' => 6]], $holds);
    }

    public function test_legacy_mode_returns_enabled_false_and_empty_arrays(): void
    {
        $this->row($this->workspace, 'referral:r1', CreditAccountant::SAVED, 'credit', 30);
        $sub = Subscription::create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id, 'plan_key' => 'starter', 'billing_interval' => 'monthly',
            'status' => 'active', 'current_period_start' => now()->subDay(), 'current_period_end' => now()->addMonth()]);
        $sub->periods()->create(['period_start' => now()->subDay(), 'period_end' => now()->addMonth(), 'documents_allowed' => 20, 'comparisons_allowed' => 5,
            'storage_bytes' => 1073741824, 'ai_credits_allowed' => null]);

        $this->activity()->assertOk()->assertJsonPath('data', ['enabled' => false, 'saved_sources' => [], 'holds' => [], 'recent' => []]);

        config(['ai_credits.enabled' => false]);
        $this->activity()->assertOk()->assertJsonPath('data.enabled', false);
    }

    public function test_unauthenticated_requests_get_401(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/credits/activity')->assertStatus(401);
    }
}
