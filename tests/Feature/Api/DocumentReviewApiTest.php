<?php

namespace Tests\Feature\Api;

use App\Models\Document;
use App\Models\User;
use Database\Seeders\DocumentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentReviewApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DocumentSeeder::class);
    }

    private function authenticateAs(string $role = 'Reviewer'): User
    {
        $user = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_approve_requires_authentication(): void
    {
        $doc = Document::where('status', 'Needs Review')->firstOrFail();

        $this->postJson("/api/documents/{$doc->id}/approve")->assertStatus(401);
    }

    public function test_reject_requires_authentication(): void
    {
        $doc = Document::where('status', 'Needs Review')->firstOrFail();

        $this->postJson("/api/documents/{$doc->id}/reject")->assertStatus(401);
    }

    public function test_viewer_cannot_approve(): void
    {
        $this->authenticateAs('Viewer');
        $doc = Document::where('status', 'Needs Review')->firstOrFail();

        $this->postJson("/api/documents/{$doc->id}/approve")
            ->assertStatus(403)
            ->assertJson(['message' => 'This action is unauthorized.']);
    }

    public function test_reviewer_can_approve_needs_review_document(): void
    {
        $user = $this->authenticateAs('Reviewer');
        $doc = Document::where('status', 'Needs Review')->firstOrFail();

        $response = $this->postJson("/api/documents/{$doc->id}/approve");

        $response->assertOk()
            ->assertJsonPath('data.status', 'Ready')
            ->assertJsonPath('data.errorMessage', null);

        $this->assertDatabaseHas('documents', [
            'id' => $doc->id,
            'status' => 'Ready',
            'last_updated_by' => $user->id,
        ]);
    }

    public function test_approve_rejects_non_review_status(): void
    {
        $this->authenticateAs('Reviewer');
        $doc = Document::where('status', 'Ready')->firstOrFail();

        $this->postJson("/api/documents/{$doc->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only documents with status "Needs Review" can be approved.');
    }

    public function test_reviewer_can_reject_needs_review_document_with_note(): void
    {
        $user = $this->authenticateAs('Reviewer');
        $doc = Document::where('status', 'Needs Review')->firstOrFail();

        $response = $this->postJson("/api/documents/{$doc->id}/reject", [
            'note' => 'Tables on pages 4–6 could not be verified.',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'Failed')
            ->assertJsonPath('data.errorMessage', 'Tables on pages 4–6 could not be verified.');

        $this->assertDatabaseHas('documents', [
            'id' => $doc->id,
            'status' => 'Failed',
            'last_updated_by' => $user->id,
        ]);
    }

    public function test_reject_without_note_uses_default_message(): void
    {
        $this->authenticateAs('Administrator');
        $doc = Document::where('status', 'Needs Review')->firstOrFail();

        $this->postJson("/api/documents/{$doc->id}/reject")
            ->assertOk()
            ->assertJsonPath('data.errorMessage', 'Document rejected during review.');
    }

    public function test_reject_rejects_non_review_status(): void
    {
        $this->authenticateAs('Reviewer');
        $doc = Document::where('status', 'Processing')->firstOrFail();

        $this->postJson("/api/documents/{$doc->id}/reject")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only documents with status "Needs Review" can be rejected.');
    }
}
