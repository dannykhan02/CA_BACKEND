<?php

namespace Tests\Feature;

use App\Models\KpiAlias;
use App\Models\KpiDefinition;
use App\Models\Workspace;
use App\Services\Kpis\KpiIdentityResolver;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KpiIdentityConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    public function test_concurrent_label_variants_create_one_definition_and_reusable_aliases(): void
    {
        if (! function_exists('pcntl_fork') || DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL and pcntl for independent workers.');
        }
        config(['kpi_identity.semantic_matching' => false]);
        $workspace = Workspace::create(['type' => 'Organization', 'name' => 'Concurrent KPI test']);
        $directory = sys_get_temp_dir().'/kpi-race-'.bin2hex(random_bytes(8));
        mkdir($directory);
        DB::disconnect();
        $children = [];
        foreach (['Internal Service Charter Performance', 'Internal Charter Performance'] as $index => $label) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                try {
                    $deadline = microtime(true) + 10;
                    while (! file_exists($directory.'/start') && microtime(true) < $deadline) {
                        usleep(1000);
                    }
                    $result = app(KpiIdentityResolver::class)->resolve($workspace->id, ['label' => $label]);
                    file_put_contents($directory.'/result-'.$index, $result['definition_id']);
                    exit(0);
                } catch (\Throwable $e) {
                    file_put_contents($directory.'/error-'.$index, $e->getMessage());
                    exit(1);
                }
            }
            $children[] = $pid;
        }
        touch($directory.'/start');
        try {
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0,
                    implode("\n", array_map('file_get_contents', glob($directory.'/error-*'))));
            }
            $this->assertSame(file_get_contents($directory.'/result-0'), file_get_contents($directory.'/result-1'));
            $this->assertSame(1, KpiDefinition::where('workspace_id', $workspace->id)->count());
            $this->assertSame(2, KpiAlias::where('workspace_id', $workspace->id)->count());
        } finally {
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
