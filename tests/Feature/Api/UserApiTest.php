<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_update_full_name(): void
    {
        $user = User::factory()->create(['full_name' => 'Old Name']);
        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/user', ['full_name' => 'New Name']);

        $response->assertOk();
        $this->assertSame('New Name', $response->json('data.user.full_name'));
        $this->assertSame('New Name', $user->fresh()->full_name);
    }

    public function test_empty_patch_body_is_a_no_op_success(): void
    {
        $user = User::factory()->create(['full_name' => 'Unchanged Name']);
        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/user', []);

        $response->assertOk();
        $this->assertSame('Unchanged Name', $user->fresh()->full_name);
    }

    public function test_blank_full_name_is_rejected(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/user', ['full_name' => '   ']);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
    }

    public function test_role_field_is_not_accepted_via_patch_user(): void
    {
        $user = User::factory()->create(['role' => 'Viewer']);
        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/user', [
            'full_name' => 'Same Person',
            'role' => 'Administrator',
        ]);

        $response->assertOk();
        $this->assertSame('Viewer', $user->fresh()->role);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->patchJson('/api/user', ['full_name' => 'New Name']);

        $response->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => 'Unauthenticated.',
            'errors' => [],
        ]);
    }

    public function test_authenticated_user_can_change_password(): void
    {
        Notification::fake();

        $user = User::factory()->create(['password' => bcrypt('OldPassword1!')]);
        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/user/password', [
            'current_password' => 'OldPassword1!',
            'password' => 'NewPassword2!',
            'password_confirmation' => 'NewPassword2!',
        ]);

        $response->assertOk();
        $this->assertTrue(Hash::check('NewPassword2!', $user->fresh()->password));
        Notification::assertSentTo($user, PasswordChangedNotification::class);
    }

    public function test_password_change_with_wrong_current_password_is_rejected(): void
    {
        $user = User::factory()->create(['password' => bcrypt('OldPassword1!')]);
        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/user/password', [
            'current_password' => 'WrongPassword!',
            'password' => 'NewPassword2!',
            'password_confirmation' => 'NewPassword2!',
        ]);

        $response->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => 'Current password is incorrect.',
            'errors' => [],
        ]);
        $this->assertTrue(Hash::check('OldPassword1!', $user->fresh()->password));
    }

    public function test_new_password_same_as_current_is_rejected(): void
    {
        $user = User::factory()->create(['password' => bcrypt('SamePassword1!')]);
        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/user/password', [
            'current_password' => 'SamePassword1!',
            'password' => 'SamePassword1!',
            'password_confirmation' => 'SamePassword1!',
        ]);

        $response->assertStatus(422);
    }

    public function test_password_change_revokes_other_sessions_but_keeps_current_one(): void
    {
        $user = User::factory()->create(['password' => bcrypt('OldPassword1!')]);
        $otherToken = $user->createToken('other-device');
        $currentToken = $user->createToken('this-device');

        $response = $this->patchJson('/api/user/password', [
            'current_password' => 'OldPassword1!',
            'password' => 'NewPassword2!',
            'password_confirmation' => 'NewPassword2!',
        ], ['Authorization' => "Bearer {$currentToken->plainTextToken}"]);

        $response->assertOk();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $otherToken->accessToken->id]);
    }
}
