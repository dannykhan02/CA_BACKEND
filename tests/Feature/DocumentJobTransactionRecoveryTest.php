<?php

namespace Tests\Feature;

use App\Enums\WorkspaceType;
use App\Jobs\ClassifyDocumentTypeJob;
use App\Jobs\DetectDocumentDeadlinesJob;
use App\Jobs\DetectDocumentRisksJob;
use App\Jobs\ExtractDocumentEntitiesJob;
use App\Jobs\GenerateInsightsJob;
use App\Models\Document;
use App\Models\DocumentDeadline;
use App\Models\DocumentEntity;
use App\Models\DocumentKpi;
use App\Models\DocumentRisk;
use App\Models\DocumentTypeClassification;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AnthropicClient;
use App\Services\Pipeline\PipelineStageRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DocumentJobTransactionRecoveryTest extends TestCase
{
    // RefreshDatabase's outer transaction would exercise savepoints instead of
    // the top-level commit/rollback boundaries used by queue workers.
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        try {
            $this->truncateTablesForAllConnections();
        } finally {
            parent::tearDown();
        }
    }

    public static function transactionalJobs(): array
    {
        return [
            'deadlines' => [DetectDocumentDeadlinesJob::class, DocumentDeadline::class],
            'risks' => [DetectDocumentRisksJob::class, DocumentRisk::class],
            'entities' => [ExtractDocumentEntitiesJob::class, DocumentEntity::class],
            'insights' => [GenerateInsightsJob::class, DocumentKpi::class],
        ];
    }

    #[DataProvider('transactionalJobs')]
    public function test_sql_failure_rolls_back_before_all_five_jobs_reuse_the_connection(string $jobClass, string $modelClass): void
    {
        $this->assertSame('pgsql', DB::getDriverName(), 'This regression requires real PostgreSQL.');
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($pdo->inTransaction());

        $user = User::factory()->create();
        $workspace = Workspace::create(['type' => WorkspaceType::Organization, 'name' => 'Transaction recovery']);
        $document = Document::create([
            'name' => 'Recovery.pdf', 'type' => 'PDF', 'size_kb' => 1,
            'status' => 'Processing', 'classification' => 'Public', 'year' => 2026,
            'workspace_id' => $workspace->id, 'uploaded_by' => $user->id,
            'extracted_text' => 'Revenue is 100. Submit the report by September 30.',
        ]);

        // Only external AI is mocked. Every model write, transaction and
        // stage-recording operation below uses the real database connection.
        $client = $this->mock(AnthropicClient::class, function ($mock) {
            $mock->shouldReceive('classifyDocumentType')->andReturn([
                'document_type' => 'financial_report', 'confidence' => 0.9,
                'reasoning' => 'Contains revenue.', 'prompt_version' => 1,
            ]);
            $mock->shouldReceive('extractDocumentEntities')->andReturn([
                'entities' => [['entity_type' => 'organization', 'value' => 'Example', 'confidence' => 0.9]],
                'prompt_version' => 1,
            ]);
            $mock->shouldReceive('detectDocumentRisks')->andReturn([
                'risks' => [[
                    'title' => 'Late filing', 'description' => 'Filing risk.',
                    'severity' => 'low', 'confidence' => 0.9, 'evidence' => 'Submit the report.',
                ]], 'prompt_version' => 1,
            ]);
            $mock->shouldReceive('detectDocumentDeadlines')->andReturn([
                'deadlines' => [[
                    'title' => 'Submit report', 'description' => 'Annual filing.',
                    'due_date' => '2026-09-30', 'date_type' => 'explicit',
                    'confidence' => 0.9, 'evidence' => 'By September 30.',
                ]], 'prompt_version' => 1,
            ]);
            $mock->shouldReceive('extractDocumentInsights')->andReturn([
                'kpis' => [['label' => 'Revenue', 'value' => '100']],
                'charts' => [], 'insights' => ['Revenue is 100.'],
            ]);
        });
        $recorder = app(PipelineStageRecorder::class);

        $injected = false;
        Event::listen('eloquent.created: '.$modelClass, function () use (&$injected, $connection) {
            if (! $injected) {
                $injected = true;
                $this->assertSame(1, $connection->transactionLevel());
                // A real SQL error AFTER a successful INSERT poisons PostgreSQL's
                // transaction. A PHP exception alone would not exercise 25P02.
                $connection->select('SELECT 1 / 0');
            }
        });

        try {
            (new $jobClass($document->id, true))->handle($client, $recorder);
            $this->fail('Expected the injected database error to escape the job.');
        } catch (QueryException $error) {
            $this->assertSame('22012', $error->errorInfo[0]);
        }

        $this->assertTrue($injected);
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($pdo->inTransaction());
        $this->assertSame($pdo, $connection->getPdo(), 'Recovery must not rely on reconnecting.');
        $this->assertSame(0, $modelClass::where('document_id', $document->id)->count(), 'The successful INSERT must roll back.');
        $this->assertSame('Processing', $document->fresh()->status);

        foreach ([
            ClassifyDocumentTypeJob::class, ExtractDocumentEntitiesJob::class,
            DetectDocumentRisksJob::class, DetectDocumentDeadlinesJob::class,
            GenerateInsightsJob::class,
        ] as $nextJob) {
            (new $nextJob($document->id, true))->handle($client, $recorder);
            $this->assertSame($pdo, $connection->getPdo());
            $this->assertSame(0, $connection->transactionLevel());
            $this->assertFalse($pdo->inTransaction());
        }

        foreach ([DocumentTypeClassification::class, DocumentEntity::class, DocumentRisk::class, DocumentDeadline::class, DocumentKpi::class] as $resultModel) {
            $this->assertSame(1, $resultModel::where('document_id', $document->id)->count());
        }
        $this->assertSame('Ready', $document->fresh()->status);
        $this->assertSame(5, $document->processingJobs()->where('status', 'completed')->count());
    }
}
