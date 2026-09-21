<?php

namespace Tests\Feature;

use App\Jobs\GenerateInsightsJob;
use App\Models\Document;
use App\Models\User;
use App\Models\WorkspaceCredit;
use App\Services\AnthropicClient;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorkspaceCreditConcurrencyTest extends TestCase
{
    // Fixtures must commit so two independent worker connections can see them.
    use DatabaseTruncation;

    public function test_concurrent_ready_jobs_cannot_overspend_one_credit(): void
    {
        if (! function_exists('pcntl_fork') || DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL and pcntl for independent worker processes.');
        }

        $user = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $workspace->credits()->update(['documents_remaining' => 1]);
        $ids = [];
        for ($i = 0; $i < 2; $i++) {
            $ids[] = Document::create([
                'name' => "Concurrent {$i}.pdf", 'type' => 'PDF', 'size_kb' => 1,
                'status' => 'Processing', 'classification' => 'Public', 'year' => 2026,
                'workspace_id' => $workspace->id, 'uploaded_by' => $user->id,
                'extracted_text' => 'Revenue increased.',
            ])->id;
        }

        $directory = sys_get_temp_dir().'/credit-race-'.bin2hex(random_bytes(8));
        mkdir($directory);
        // Never inherit an open PDO socket into either worker.
        DB::disconnect();
        $children = [];
        foreach ($ids as $index => $id) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                try {
                    $this->mock(AnthropicClient::class, fn ($mock) => $mock->shouldReceive('extractDocumentInsights')->andReturn([]));
                    (new GenerateInsightsJob($id))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));
                    exit(Document::findOrFail($id)->status === 'Ready' ? 0 : 1);
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
            $this->assertSame(1, Document::whereIn('id', $ids)->where('status', 'Ready')->whereNotNull('credit_accounted_at')->count());
            $this->assertSame(0, WorkspaceCredit::where('workspace_id', $workspace->id)->firstOrFail()->documents_remaining);
        } finally {
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
