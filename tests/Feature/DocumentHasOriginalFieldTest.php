<?php

namespace Tests\Feature;

use App\Enums\WorkspaceType;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * `hasOriginal` on the document resources.
 *
 * The field exists so the frontend can say "no original file is stored" where
 * it is true, instead of offering a download that returns an error. It means
 * exactly **"a storage key is recorded on the row"**, decided by
 * `Document::hasRecordedOriginal()` — the same method the download guard uses,
 * so the two cannot disagree about which documents are downloadable.
 *
 * It does **not** mean the bytes resolve, and proving that it never asks is
 * half of what this file is for: a list page renders many rows, and one
 * `exists()` per row would be an N+1 of round trips against R2 on every
 * render of the document list.
 */
class DocumentHasOriginalFieldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    /** @return array{0: User, 1: Workspace} */
    private function orgUser(): array
    {
        $user = User::factory()->create(['role' => 'Administrator']);

        $workspace = Workspace::create([
            'type' => WorkspaceType::Organization,
            'name' => 'Org '.$user->id,
        ]);

        WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => 'Administrator',
            'joined_at' => now(),
        ]);

        $user->forceFill(['current_workspace_id' => $workspace->id])->save();

        return [$user, $workspace];
    }

    private function document(Workspace $workspace, string $name, ?string $filePath): Document
    {
        return Document::create([
            'name' => $name,
            'type' => 'PDF',
            'size_kb' => 1,
            'status' => 'Ready',
            'classification' => 'Internal',
            'year' => 2026,
            'workspace_id' => $workspace->id,
            'uploaded_by' => User::factory()->create()->id,
            'last_updated_by' => User::factory()->create()->id,
            'file_path' => $filePath,
        ]);
    }

    /** The four cases the guard has to agree with, straight off the model. */
    public function test_the_model_method_treats_null_empty_and_whitespace_as_absent(): void
    {
        [, $workspace] = $this->orgUser();

        $this->assertTrue($this->document($workspace, 'a', 'ws/abc.pdf')->hasRecordedOriginal());
        $this->assertFalse($this->document($workspace, 'b', null)->hasRecordedOriginal());
        $this->assertFalse($this->document($workspace, 'c', '')->hasRecordedOriginal());
        $this->assertFalse($this->document($workspace, 'd', "  \t ")->hasRecordedOriginal());
    }

    /**
     * The list endpoint, with all four cases present at once, and a spy in
     * place of the `documents` disk: listing documents must not touch storage.
     *
     * The spy is the assertion. If any code path on this request called
     * `exists()`, `download()` or `get()` on the disk, this fails — which is
     * what would happen if `hasOriginal` were ever "fixed" to answer the
     * honest-looking question (do the bytes resolve?) by asking R2 per row.
     */
    public function test_listing_documents_reports_has_original_without_any_disk_call(): void
    {
        [$user, $workspace] = $this->orgUser();

        $this->document($workspace, 'recorded.pdf', 'documents/'.$workspace->id.'/abc.pdf');
        $this->document($workspace, 'null-path.pdf', null);
        $this->document($workspace, 'empty-path.pdf', '');
        $this->document($workspace, 'blank-path.pdf', "  \t ");

        $spy = Mockery::spy(Filesystem::class);
        Storage::set('documents', $spy);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/documents?per_page=50');
        $response->assertOk();

        $byName = collect($response->json('data'))->keyBy('name');
        $this->assertCount(4, $byName, 'all four documents should be listed');

        $this->assertTrue($byName['recorded.pdf']['hasOriginal']);
        $this->assertFalse($byName['null-path.pdf']['hasOriginal']);
        $this->assertFalse($byName['empty-path.pdf']['hasOriginal']);
        $this->assertFalse($byName['blank-path.pdf']['hasOriginal']);

        $spy->shouldNotHaveReceived('exists');
        $spy->shouldNotHaveReceived('fileExists');
        $spy->shouldNotHaveReceived('get');
        $spy->shouldNotHaveReceived('download');
        $spy->shouldNotHaveReceived('readStream');
        $spy->shouldNotHaveReceived('size');
        $spy->shouldNotHaveReceived('url');
    }

    /** Same field, same meaning, on the single-document resource. */
    public function test_the_show_endpoint_reports_has_original_for_a_recorded_and_an_absent_key(): void
    {
        [$user, $workspace] = $this->orgUser();
        $recorded = $this->document($workspace, 'recorded.pdf', 'documents/'.$workspace->id.'/abc.pdf');
        $absent = $this->document($workspace, 'null-path.pdf', null);

        Sanctum::actingAs($user);

        $this->getJson("/api/documents/{$recorded->id}")
            ->assertOk()->assertJsonPath('data.hasOriginal', true);

        $this->getJson("/api/documents/{$absent->id}")
            ->assertOk()->assertJsonPath('data.hasOriginal', false);
    }

    /**
     * The field is about the row, not the bucket. A recorded key whose object
     * is gone still reports `true` — that case is a storage anomaly for
     * `documents:audit-originals` and alerting to catch, and collapsing it
     * into `hasOriginal: false` would hide it behind a benign-looking UI
     * state.
     */
    public function test_a_recorded_key_with_no_object_still_reports_has_original_true(): void
    {
        [$user, $workspace] = $this->orgUser();
        $path = 'documents/'.$workspace->id.'/gone.pdf';
        $document = $this->document($workspace, 'gone.pdf', $path);
        $this->assertFalse(Storage::disk('documents')->exists($path));

        Sanctum::actingAs($user);
        $this->getJson("/api/documents/{$document->id}")
            ->assertOk()->assertJsonPath('data.hasOriginal', true);
    }
}
