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
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorkspaceCreditConcurrencyTest extends TestCase
{
    // Fixtures must commit so two independent worker connections can see them.
    use DatabaseTruncation;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['document_intelligence.revision_v1' => true, 'ai_credits.enabled' => false]);
    }

    private function workspace(int $balance): Workspace
    {
        $this->user = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($this->user);
        $workspace->credits()->update(['documents_remaining' => $balance]);

        return $workspace;
    }

    /** A document reserved and analyzed to delivery, waiting only for its revision to publish. */
    private function candidate(Workspace $workspace, string $name): array
    {
        $document = Document::create(['name' => $name, 'type' => 'DOCX', 'size_kb' => 1, 'status' => 'Processing',
            'classification' => 'Public', 'year' => 2026, 'workspace_id' => $workspace->id,
            'uploaded_by' => $this->user->id, 'file_hash' => hash('sha256', $name),
            'extracted_text' => 'Revenue grew in the quarter.']);
        app(EntitlementService::class)->reserveDocument($document);
        $revision = app(DocumentRevisionPublisher::class)->begin($document->fresh());
        $document->fresh()->forceFill(['ai_pipeline' => ['route' => 'normal'], 'insights' => ['ok']])->save();
        DocumentIntelligenceSummary::create(['document_id' => $document->id, 'workspace_id' => $workspace->id,
            'intelligence_revision_id' => $revision->id, 'executive_summary' => 'Summary', 'key_findings' => [],
            'critical_risks' => [], 'upcoming_deadlines' => [], 'important_entities' => [],
            'recommended_attention' => [], 'prompt_version' => 'test']);
        foreach (['ai_analysis', 'document_type', 'entities', 'risks', 'deadlines', 'document_summary'] as $stage) {
            ProcessingJob::create(['workspace_id' => $workspace->id, 'document_id' => $document->id,
                'intelligence_revision_id' => $revision->id, 'stage' => $stage, 'status' => 'completed']);
        }

        return [$document->id, $revision->id];
    }

    /**
     * Runs $work in $count independent processes and asserts every one exited 0.
     *
     * @param  callable(int): void  $work
     */
    private function race(int $count, callable $work): void
    {
        if (! function_exists('pcntl_fork') || DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL and pcntl for independent worker processes.');
        }
        $directory = sys_get_temp_dir().'/credit-race-'.bin2hex(random_bytes(8));
        mkdir($directory);
        // Never inherit an open PDO socket into either worker.
        DB::disconnect();
        $children = [];
        for ($index = 0; $index < $count; $index++) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                try {
                    $work($index);
                    exit(0);
                } catch (HttpException $error) {
                    exit($error->getStatusCode() === 402 ? 0 : 1);
                } catch (\Throwable $error) {
                    file_put_contents($directory.'/error-'.$index, $error->getMessage());
                    exit(1);
                }
            }
            $children[] = $pid;
        }
        try {
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $errors = array_map('file_get_contents', glob($directory.'/error-*'));
                $this->assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, implode("\n", $errors));
            }
        } finally {
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function test_concurrent_publications_cannot_overspend_one_credit(): void
    {
        // Two credits buy two reservations; the balance is then squeezed to one, which is the
        // shape a mid-pipeline refund or adjustment leaves behind.
        $workspace = $this->workspace(2);
        $candidates = [$this->candidate($workspace, 'First.docx'), $this->candidate($workspace, 'Second.docx')];
        $workspace->credits()->update(['documents_remaining' => 1]);

        $this->race(2, function (int $index) use ($candidates): void {
            [$documentId, $revisionId] = $candidates[$index];
            app(DocumentRevisionPublisher::class)->publish(Document::findOrFail($documentId), $revisionId);
        });

        $ids = array_column($candidates, 0);
        $this->assertSame(0, WorkspaceCredit::where('workspace_id', $workspace->id)->firstOrFail()->documents_remaining,
            'the balance floors at zero and never goes negative');
        $this->assertSame(2, Document::whereIn('id', $ids)->whereNotNull('credit_accounted_at')->count());
        // Each document is charged exactly once even though they settled concurrently.
        foreach ($ids as $id) {
            $this->assertSame(1, (int) DB::table('credit_ledger')->where('related_id', $id)
                ->where('direction', 'debit')->count());
        }
    }

    public function test_two_workers_publishing_the_same_revision_charge_exactly_once(): void
    {
        $workspace = $this->workspace(5);
        [$documentId, $revisionId] = $this->candidate($workspace, 'Contested.docx');

        $this->race(4, function () use ($documentId, $revisionId): void {
            try {
                app(DocumentRevisionPublisher::class)->publish(Document::findOrFail($documentId), $revisionId);
            } catch (\LogicException $expected) {
                // A loser of the race sees the revision already published and owned by nobody.
            }
        });

        $this->assertSame(4, WorkspaceCredit::where('workspace_id', $workspace->id)->firstOrFail()->documents_remaining);
        $this->assertSame(1, (int) DB::table('credit_ledger')->where('workspace_id', $workspace->id)
            ->where('direction', 'debit')->where('unit', 'saved_document')->sum('amount'));
        $this->assertSame($revisionId, Document::findOrFail($documentId)->published_intelligence_revision_id);
    }
}
