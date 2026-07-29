<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_list_users(): void
    {
        $viewer = User::factory()->create(['role' => 'Viewer']);

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/admin/users')
            ->assertStatus(403);
    }

    public function test_admin_can_list_users(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator']);
        User::factory()->count(3)->create(['role' => 'Viewer']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users')
            ->assertStatus(200)
            ->assertJsonCount(4, 'data');
    }

    public function test_admin_can_update_a_users_role(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator']);
        $target = User::factory()->create(['role' => 'Viewer']);

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
