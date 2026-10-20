<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentIntelligenceSummary;
use App\Models\ProcessingJob;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCredit;
use App\Services\EntitlementService;
use App\Services\Intelligence\DocumentRevisionPublisher;
use App\Services\WorkspaceCreditService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Charge-on-publish policy (docs/billing-credits.md): one credit is charged when a revision
 * publishes, and never for an analysis that ends without a published revision.
 * Legacy one-document-unit accounting, which is what a workspace without AI credits uses.
 */
class CreditChargeOnPublishTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private static int $hashes = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['document_intelligence.revision_v1' => true, 'ai_credits.enabled' => false]);
    }

    private function workspace(int $balance = 5): Workspace
    {
        $this->user = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($this->user);
        $workspace->credits()->update(['documents_remaining' => $balance]);
        Sanctum::actingAs($this->user->fresh());

        return $workspace;
    }

    private function document(Workspace $workspace, string $status = 'Processing'): Document
    {
        return Document::create(['name' => 'Report.docx', 'type' => 'DOCX', 'size_kb' => 1, 'status' => $status,
            'classification' => 'Public', 'year' => 2026, 'workspace_id' => $workspace->id,
            'uploaded_by' => $this->user->id, 'file_hash' => hash('sha256', 'fixture'.self::$hashes++),
            'extracted_text' => 'Revenue grew in the quarter.']);
    }

    /** Everything publish() requires of a candidate revision, short of publishing it. */
    private function deliver(Document $document, string $revisionId): void
    {
        $document->forceFill(['ai_pipeline' => ['route' => 'normal'], 'insights' => ['ok']])->save();
        DocumentIntelligenceSummary::create(['document_id' => $document->id, 'workspace_id' => $document->workspace_id,
            'intelligence_revision_id' => $revisionId, 'executive_summary' => 'Summary', 'key_findings' => [],
            'critical_risks' => [], 'upcoming_deadlines' => [], 'important_entities' => [],
            'recommended_attention' => [], 'prompt_version' => 'test']);
        foreach (['ai_analysis', 'document_type', 'entities', 'risks', 'deadlines', 'document_summary'] as $stage) {
            ProcessingJob::create(['workspace_id' => $document->workspace_id, 'document_id' => $document->id,
                'intelligence_revision_id' => $revisionId, 'stage' => $stage, 'status' => 'completed']);
        }
    }

    /** Reserve, run the analysis to delivery, and return the candidate revision id. */
    private function analyze(Document $document): string
    {
        app(EntitlementService::class)->reserveDocument($document);
        $revision = app(DocumentRevisionPublisher::class)->begin($document->fresh());
        $this->deliver($document->fresh(), $revision->id);

        return $revision->id;
    }

    private function remaining(Workspace $workspace): int
    {
        return (int) WorkspaceCredit::where('workspace_id', $workspace->id)->firstOrFail()->documents_remaining;
    }

    private function op(Document $document): ?object
    {
        return DB::table('billing_operations')->where('kind', 'document')->where('resource_id', $document->id)->first();
    }

    private function debits(Workspace $workspace): int
    {
        return (int) DB::table('credit_ledger')->where('workspace_id', $workspace->id)
            ->where('direction', 'debit')->where('unit', 'saved_document')->sum('amount');
    }

    // ------------------------------------------------------------------ the charge point

    public function test_publishing_a_revision_charges_exactly_one_credit(): void
    {
        $workspace = $this->workspace(5);
        $document = $this->document($workspace);
        $revisionId = $this->analyze($document);

        app(DocumentRevisionPublisher::class)->publish($document->fresh(), $revisionId);

        self::assertSame(4, $this->remaining($workspace));
        self::assertSame(1, $this->debits($workspace));
        self::assertNotNull($document->fresh()->credit_accounted_at);
        self::assertSame('completed', $this->op($document)->status);
        self::assertSame('Ready', $document->fresh()->status);
    }

    public function test_delivering_every_stage_without_publishing_charges_nothing(): void
    {
        $workspace = $this->workspace(5);
        $document = $this->document($workspace);
        $this->analyze($document);

        // The summary stage has delivered and every required stage is complete, but no revision
        // published: under the old policy this was already a settled, stamped document.
        self::assertSame(5, $this->remaining($workspace));
        self::assertSame(0, $this->debits($workspace));
        self::assertNull($document->fresh()->credit_accounted_at);
        self::assertSame('reserved', $this->op($document)->status);
    }

    public function test_required_stage_failing_after_delivery_charges_nothing_and_restores_the_balance(): void
    {
        $workspace = $this->workspace(5);
        $document = $this->document($workspace);
        $this->analyze($document);

        // Deadlines blew up after the summary delivered: the revision never publishes.
        $document->fresh()->forceFill(['status' => 'Needs Review'])->save();
        app(EntitlementService::class)->releaseDocumentReservation($document->fresh(), 'stage_failed');

        self::assertSame(5, $this->remaining($workspace));
        self::assertSame(0, $this->debits($workspace));
        self::assertNull($document->fresh()->credit_accounted_at);
        self::assertSame('released', $this->op($document)->status);
        self::assertSame(5, app(EntitlementService::class)->summary($workspace->id)['documents_remaining']);
    }

    // ------------------------------------------------------------------ idempotence

    public function test_publishing_twice_charges_once(): void
    {
        $workspace = $this->workspace(5);
        $document = $this->document($workspace);
        $revisionId = $this->analyze($document);
        $publisher = app(DocumentRevisionPublisher::class);

        $publisher->publish($document->fresh(), $revisionId);
        $publisher->publish($document->fresh(), $revisionId);

        self::assertSame(4, $this->remaining($workspace));
        self::assertSame(1, $this->debits($workspace));
    }

    public function test_a_later_revision_publishing_for_a_settled_document_charges_nothing(): void
    {
        $workspace = $this->workspace(5);
        $document = $this->document($workspace);
        $publisher = app(DocumentRevisionPublisher::class);
        $publisher->publish($document->fresh(), $this->analyze($document));
        self::assertSame(4, $this->remaining($workspace));

        $second = $publisher->begin($document->fresh());
        $this->deliver($document->fresh(), $second->id);
        $publisher->publish($document->fresh(), $second->id);

        self::assertSame(4, $this->remaining($workspace), 'a reprocess of a settled document is free');
        self::assertSame(1, $this->debits($workspace));
    }

    public function test_transient_failure_then_success_on_retry_charges_once_and_holds_one_reservation(): void
    {
        $workspace = $this->workspace(5);
        $document = $this->document($workspace);
        $revisionId = $this->analyze($document);

        // A transient worker failure: the reservation stays held across the retry, nothing is released.
        app(EntitlementService::class)->reserveDocument($document->fresh());
        self::assertSame('reserved', $this->op($document)->status);
        self::assertSame(1, (int) $this->op($document)->attempt_number);

        app(DocumentRevisionPublisher::class)->publish($document->fresh(), $revisionId);

        self::assertSame(4, $this->remaining($workspace));
        self::assertSame(1, $this->debits($workspace));
        self::assertSame(1, (int) $this->op($document)->attempt_number);
    }

    public function test_exhausted_retries_release_the_reservation_without_charging(): void
    {
        $workspace = $this->workspace(5);
        $document = $this->document($workspace);
        $this->analyze($document);

        $document->fresh()->forceFill(['status' => 'Failed'])->save();
        app(EntitlementService::class)->releaseDocumentReservation($document->fresh(), 'document_failed');

        self::assertSame(5, $this->remaining($workspace));
        self::assertSame(0, $this->debits($workspace));
        self::assertSame('released', $this->op($document)->status);
    }

    // ------------------------------------------------------------------ release semantics

    public function test_release_is_idempotent_and_never_releases_a_settled_document(): void
    {
        $workspace = $this->workspace(5);
        $entitlements = app(EntitlementService::class);

        $released = $this->document($workspace);
        $this->analyze($released);
        $released->fresh()->forceFill(['status' => 'Failed'])->save();
        self::assertTrue($entitlements->releaseDocumentReservation($released->fresh(), 'document_failed'));
        self::assertFalse($entitlements->releaseDocumentReservation($released->fresh(), 'document_failed'));
        self::assertSame('released', $this->op($released)->status);

        $settled = $this->document($workspace);
        app(DocumentRevisionPublisher::class)->publish($settled->fresh(), $this->analyze($settled));
        self::assertFalse($entitlements->releaseDocumentReservation($settled->fresh(), 'document_failed'));
        self::assertSame('completed', $this->op($settled)->status);
        self::assertSame(4, $this->remaining($workspace), 'releasing a settled document refunds nothing');
    }

    public function test_deleting_a_document_mid_processing_releases_its_reservation(): void
    {
        $workspace = $this->workspace(5);
        $document = $this->document($workspace);
        $this->analyze($document);

        $this->deleteJson("/api/documents/{$document->id}")->assertOk();

        self::assertSame('released', $this->op($document)->status);
        self::assertSame(5, $this->remaining($workspace));
        self::assertSame(0, $this->debits($workspace));
    }

    public function test_the_stale_sweep_dry_run_changes_nothing(): void
    {
        $workspace = $this->workspace(5);
        $document = $this->document($workspace);
        $this->analyze($document);
        $document->fresh()->forceFill(['status' => 'Failed'])->save();

        self::assertSame(0, Artisan::call('billing:release-stale-reservations', ['--dry-run' => true]));

        self::assertSame('reserved', $this->op($document)->status);
    }

    // ------------------------------------------------------------------ legacy and admission

    public function test_a_legacy_settled_document_is_never_recharged_and_release_is_a_noop(): void
    {
        $workspace = $this->workspace(5);
        $document = $this->document($workspace, 'Ready');
        // A document settled under the old policy, with no published revision it can point to.
        $document->forceFill(['credit_accounted_at' => now()])->save();

        app(WorkspaceCreditService::class)->accountForReadyDocument($document->fresh());
        self::assertFalse(app(EntitlementService::class)->releaseDocumentReservation($document->fresh(), 'document_failed'));

        self::assertSame(5, $this->remaining($workspace));
        self::assertSame(0, $this->debits($workspace));
    }

    public function test_insufficient_credits_still_blocks_starting(): void
    {
        $workspace = $this->workspace(0);
        $document = $this->document($workspace);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('No processing credits remain.');
        app(EntitlementService::class)->reserveDocument($document);
    }

    public function test_merge_time_evidence_delivery_never_debits_in_legacy_mode(): void
    {
        $workspace = $this->workspace(5);
        $document = $this->document($workspace);
        app(EntitlementService::class)->reserveDocument($document);

        // MergeDocumentEvidenceJob's "evidence alone never debits" call.
        app(WorkspaceCreditService::class)->accountForReadyDocument($document->fresh(), false);

        self::assertSame(5, $this->remaining($workspace));
        self::assertSame(0, $this->debits($workspace));
        self::assertNull($document->fresh()->credit_accounted_at);
    }

    public function test_charge_lands_at_ready_when_revisions_are_disabled(): void
    {
        config(['document_intelligence.revision_v1' => false]);
        $workspace = $this->workspace(5);
        $document = $this->document($workspace);
        app(EntitlementService::class)->reserveDocument($document);

        // No revision exists to publish; the Ready transition is the charge point instead.
        app(WorkspaceCreditService::class)->accountForReadyDocument($document->fresh());

        self::assertSame(4, $this->remaining($workspace));
        self::assertSame(1, $this->debits($workspace));
        self::assertNotNull($document->fresh()->credit_accounted_at);
    }

    // ------------------------------------------------------------------ audit command

    public function test_audit_reports_settled_documents_without_a_published_revision(): void
    {
        $workspace = $this->workspace(5);
        $orphan = $this->document($workspace, 'Ready');
        $orphan->forceFill(['credit_accounted_at' => now()])->save();
        $clean = $this->document($workspace);
        app(DocumentRevisionPublisher::class)->publish($clean->fresh(), $this->analyze($clean));

        self::assertSame(0, Artisan::call('credits:audit-settlements'));
        $output = Artisan::output();

        self::assertStringContainsString($orphan->id, $output);
        self::assertStringNotContainsString($clean->id, $output);
        self::assertStringContainsString('Settled without a published revision: 1', $output);
    }

    public function test_audit_exits_non_zero_only_for_a_double_charged_document(): void
    {
        $workspace = $this->workspace(5);
        $document = $this->document($workspace, 'Ready');
        $document->forceFill(['credit_accounted_at' => now()])->save();
        foreach (['a', 'b'] as $suffix) {
            DB::table('credit_ledger')->insert([
                'workspace_id' => $workspace->id, 'reference' => 'document_debit:'.$document->id.':'.$suffix,
                'unit' => 'saved_document', 'direction' => 'debit', 'amount' => 1, 'reason' => 'document_completed',
                'related_type' => 'document', 'related_id' => $document->id, 'created_at' => now()]);
        }

        self::assertSame(1, Artisan::call('credits:audit-settlements'));
        self::assertStringContainsString('Charged more than once: 1', Artisan::output());
    }

    public function test_audit_reports_reservations_held_longer_than_a_day(): void
    {
        $workspace = $this->workspace(5);
        $document = $this->document($workspace);
        $this->analyze($document);
        DB::table('billing_operations')->where('resource_id', $document->id)
            ->update(['updated_at' => now()->subHours(30)]);

        self::assertSame(0, Artisan::call('credits:audit-settlements'));
        $output = Artisan::output();

        self::assertStringContainsString('held longer than 24h: 1', $output);
        self::assertStringContainsString($document->id, $output);
    }

    public function test_audit_limit_caps_the_listed_ids(): void
    {
        $workspace = $this->workspace(5);
        foreach (range(1, 3) as $ignored) {
            $this->document($workspace, 'Ready')->forceFill(['credit_accounted_at' => now()])->save();
        }

        self::assertSame(0, Artisan::call('credits:audit-settlements', ['--limit' => 1]));
        $output = Artisan::output();

        self::assertStringContainsString('Settled without a published revision: 3', $output);
        self::assertSame(1, substr_count($output, "\n  - "), 'only --limit ids are listed');
        self::assertStringContainsString('... 2 more', $output);
    }
}
