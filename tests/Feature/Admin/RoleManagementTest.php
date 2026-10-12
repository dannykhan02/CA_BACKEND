<?php

namespace Tests\Feature\Admin;

use App\Enums\WorkspaceType;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private function inWorkspace(User $user, Workspace $workspace): void
    {
        WorkspaceMember::create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'joined_at' => now()]);
        $user->update(['current_workspace_id' => $workspace->id]);
    }

    public function test_non_admin_cannot_list_users(): void
    {
        $viewer = User::factory()->create(['role' => 'Viewer']);

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/admin/users')
            ->assertStatus(403);
    }

    public function test_admin_can_list_users(): void
    {
        // F1-P1: listing is workspace-scoped, so admin + targets must share
        // a workspace_members row for the admin to see them.
        $workspace = Workspace::create(['type' => WorkspaceType::Organization, 'name' => 'Admin List Test WS']);
        $admin = User::factory()->create(['role' => 'Administrator']);
        $targets = User::factory()->count(3)->create(['role' => 'Viewer']);

        foreach ($targets->push($admin) as $user) {
            WorkspaceMember::create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'joined_at' => now()]);
            $user->update(['current_workspace_id' => $workspace->id]);
        }

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users')
            ->assertStatus(200)
            ->assertJsonCount(4, 'data');
    }

    public function test_admin_can_update_a_users_role(): void
    {
        $workspace = Workspace::create(['type' => WorkspaceType::Personal, 'name' => 'Role Test']);
        $admin = User::factory()->create(['role' => 'Administrator']);
        $target = User::factory()->create(['role' => 'Viewer']);
        $this->inWorkspace($admin, $workspace);
        $this->inWorkspace($target, $workspace);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/users/{$target->id}/role", ['role' => 'Analyst'])
            ->assertStatus(200)
            ->assertJsonPath('data.role', 'Analyst');

        $this->assertDatabaseHas('users', ['id' => $target->id, 'role' => 'Analyst']);
    }

    public function test_invalid_role_value_is_rejected_before_hitting_db(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator']);
        $target = User::factory()->create(['role' => 'Viewer']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/users/{$target->id}/role", ['role' => 'SuperHacker'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');

        $this->assertDatabaseHas('users', ['id' => $target->id, 'role' => 'Viewer']);
    }

    public function test_admin_cannot_change_a_foreign_workspace_users_global_role(): void
    {
        $own = Workspace::create(['type' => WorkspaceType::Personal, 'name' => 'Own']);
        $foreign = Workspace::create(['type' => WorkspaceType::Personal, 'name' => 'Foreign']);
        $admin = User::factory()->create(['role' => 'Administrator']);
        $target = User::factory()->create(['role' => 'Viewer']);
        $this->inWorkspace($admin, $own);
        $this->inWorkspace($target, $foreign);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/users/{$target->id}/role", ['role' => 'Administrator'])
            ->assertNotFound();
        $this->assertSame('Viewer', $target->fresh()->role);
    }

    public function test_shared_workspace_target_cannot_receive_a_global_privilege_change(): void
    {
        $own = Workspace::create(['type' => WorkspaceType::Organization, 'name' => 'Own']);
        $other = Workspace::create(['type' => WorkspaceType::Organization, 'name' => 'Other']);
        $admin = User::factory()->create(['role' => 'Administrator']);
        $target = User::factory()->create(['role' => 'Viewer']);
        $this->inWorkspace($admin, $own);
        $this->inWorkspace($target, $own);
        WorkspaceMember::create(['workspace_id' => $other->id, 'user_id' => $target->id, 'joined_at' => now()]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/users/{$target->id}/role", ['role' => 'Administrator'])
            ->assertNotFound();
        $this->assertSame('Viewer', $target->fresh()->role);
    }

    public function test_admin_without_current_workspace_membership_cannot_update_roles(): void
    {
        $workspace = Workspace::create(['type' => WorkspaceType::Personal, 'name' => 'Former']);
        $admin = User::factory()->create(['role' => 'Administrator', 'current_workspace_id' => $workspace->id]);
        $target = User::factory()->create(['role' => 'Viewer']);
        $this->inWorkspace($target, $workspace);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/users/{$target->id}/role", ['role' => 'Administrator'])
            ->assertForbidden();
        $this->getJson('/api/admin/users')->assertForbidden();
        $this->assertSame('Viewer', $target->fresh()->role);
    }

    public function test_last_workspace_administrator_cannot_be_demoted_by_an_administrator_elsewhere(): void
    {
        $own = Workspace::create(['type' => WorkspaceType::Personal, 'name' => 'Own']);
        $other = Workspace::create(['type' => WorkspaceType::Personal, 'name' => 'Other']);
        $admin = User::factory()->create(['role' => 'Administrator']);
        $unrelatedAdmin = User::factory()->create(['role' => 'Administrator']);
        $this->inWorkspace($admin, $own);
        $this->inWorkspace($unrelatedAdmin, $other);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/users/{$admin->id}/role", ['role' => 'Viewer'])
            ->assertStatus(422);
        $this->assertSame('Administrator', $admin->fresh()->role);
    }

    public function test_non_admin_cannot_update_roles(): void
    {
        $viewer = User::factory()->create(['role' => 'Viewer']);
        $target = User::factory()->create(['role' => 'Viewer']);

        $this->actingAs($viewer, 'sanctum')
            ->patchJson("/api/admin/users/{$target->id}/role", ['role' => 'Administrator'])
            ->assertStatus(403);

        $this->assertDatabaseHas('users', ['id' => $target->id, 'role' => 'Viewer']);
    }
}
