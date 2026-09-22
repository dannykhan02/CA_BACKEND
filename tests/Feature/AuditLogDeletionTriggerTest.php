<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditLogDeletionTriggerTest extends TestCase
{
    use RefreshDatabase;

    private function makeAuditLog(): array
    {
        $workspace = Workspace::create(['type' => 'Organization', 'name' => 'Disposable audit trigger test']);
        $user = User::factory()->create(['current_workspace_id' => $workspace->id]);
        $log = AuditLog::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'action' => 'audit.trigger.test',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'metadata' => ['proof' => true],
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ]);

        return [$user, $workspace, $log];
    }

    public function test_deleting_user_then_workspace_preserves_the_audit_row(): void
    {
        [$user, $workspace, $log] = $this->makeAuditLog();

        $user->delete();
        $this->assertNull($log->fresh()->user_id);
        $this->assertSame($workspace->id, $log->fresh()->workspace_id);

        $workspace->delete();
        $preserved = $log->fresh();
        $this->assertNotNull($preserved);
        $this->assertNull($preserved->workspace_id);
        $this->assertSame('audit.trigger.test', $preserved->action);
        $this->assertSame(['proof' => true], $preserved->metadata);
    }

    public function test_deleting_workspace_then_user_also_preserves_the_audit_row(): void
    {
        [$user, $workspace, $log] = $this->makeAuditLog();

        $workspace->delete();
        $this->assertNull($log->fresh()->workspace_id);
        $this->assertSame($user->id, $log->fresh()->user_id);

        $user->delete();
        $this->assertNull($log->fresh()->user_id);
    }

    public function test_direct_audit_tampering_remains_blocked(): void
    {
        [, , $log] = $this->makeAuditLog();

        $this->expectException(QueryException::class);
        DB::table('audit_logs')->where('id', $log->id)->update([
            'workspace_id' => null,
            'action' => 'changed',
        ]);
    }

    public function test_direct_audit_deletion_remains_blocked(): void
    {
        [, , $log] = $this->makeAuditLog();

        $this->expectException(QueryException::class);
        DB::table('audit_logs')->where('id', $log->id)->delete();
    }
}
