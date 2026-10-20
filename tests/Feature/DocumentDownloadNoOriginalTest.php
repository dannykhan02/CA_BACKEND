<?php

namespace Tests\Feature;

use App\Enums\WorkspaceType;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Downloading a document for which no original file was ever recorded.
 *
 * `documents.file_path` is nullable and production carries rows with no key:
 * six seeded documents, three of them `Ready`. The controller passed that null
 * straight into `Storage::disk('documents')->exists()`, whose Flysystem
 * `fileExists(string $location)` is typed, so the request died with an
 * uncaught TypeError and a 500 — indistinguishable from a real outage, and
 * nothing the frontend could word honestly.
 *
 * The distinction this file pins is the one that matters operationally:
 *
 * - **no key recorded** — expected, permanent, nobody is going to fix it.
 *   404 with `error.code = NO_ORIGINAL_STORED`.
 * - **key recorded, object missing** — a storage anomaly. That is exactly what
 *   the 2026-10-17 source-viewer incident looked like, and it keeps its
 *   existing bare 404 so neither the response nor any alerting built on it
 *   changes. Pinned here as a regression.
 *
 * Note which status this is *not*. 409 is already taken on this endpoint, for
 * a document whose status is not yet downloadable — a state that resolves on
 * its own once processing or approval finishes. Reusing it would tell the
 * frontend to retry something that will never succeed.
 */
class DocumentDownloadNoOriginalTest extends TestCase
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

    private function document(Workspace $workspace, ?string $filePath, string $status = 'Ready'): Document
    {
        return Document::create([
            'name' => 'seeded.pdf',
            'type' => 'PDF',
            'size_kb' => 1,
            'status' => $status,
            'classification' => 'Internal',
            'year' => 2026,
            'workspace_id' => $workspace->id,
            'uploaded_by' => User::factory()->create()->id,
            'last_updated_by' => User::factory()->create()->id,
            'file_path' => $filePath,
        ]);
    }

    /**
     * The reported defect. Before the guard this is a 500 from a TypeError
     * inside Flysystem, so asserting the status alone is the whole regression.
     */
    public function test_a_null_file_path_is_refused_with_no_original_stored_and_not_a_500(): void
    {
        [$user, $workspace] = $this->orgUser();
        $document = $this->document($workspace, null);

        Sanctum::actingAs($user);
        $response = $this->getJson("/api/documents/{$document->id}/download");

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'NO_ORIGINAL_STORED')
            ->assertJsonPath('success', false);

        $this->assertNotSame(500, $response->getStatusCode());
    }

    /**
     * An empty or whitespace-only key reached the TypeError by a different
     * road and was, if anything, worse. On a directory-backed driver
     * `exists('')` answers **true** — the disk root is a directory — so the
     * request walked straight past the missing-object check and died inside
     * `download()` on a mime-type lookup of the root:
     * `UnableToRetrieveMetadata: Unable to retrieve the mime_type for file at
     * location: .../disks/documents/`. Another 500. These are absent keys and
     * are classified with the null.
     */
    public function test_an_empty_or_whitespace_only_file_path_is_treated_as_no_original(): void
    {
        [$user, $workspace] = $this->orgUser();
        Sanctum::actingAs($user);

        foreach (['', ' ', "\t", "  \n "] as $blank) {
            $document = $this->document($workspace, $blank);

            $this->getJson("/api/documents/{$document->id}/download")
                ->assertNotFound()
                ->assertJsonPath('error.code', 'NO_ORIGINAL_STORED');
        }
    }

    /** `Needs Review` is downloadable too, so the guard must not be status-bound. */
    public function test_the_guard_also_covers_a_needs_review_document(): void
    {
        [$user, $workspace] = $this->orgUser();
        $document = $this->document($workspace, null, 'Needs Review');

        Sanctum::actingAs($user);
        $this->getJson("/api/documents/{$document->id}/download")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NO_ORIGINAL_STORED');
    }

    /**
     * Regression. A recorded key whose object is gone is a different event and
     * keeps its existing response byte for byte: 404 with **no** `error.code`,
     * which is how a caller tells the storage anomaly apart from the benign
     * case above.
     *
     * Note what that body actually is. The controller aborts with 'The
     * underlying file could not be found.', but `abort(404, ...)` throws a
     * NotFoundHttpException, and the handler for it in bootstrap/app.php
     * hardcodes `Resource not found.` and drops the message — so this 404 is
     * already indistinguishable from an unknown document id. That is pinned
     * here as the existing behaviour, not endorsed; it is the second reason
     * the new case needs a code rather than just a status.
     */
    public function test_a_recorded_key_with_a_missing_object_keeps_its_existing_404(): void
    {
        [$user, $workspace] = $this->orgUser();
        $path = 'documents/'.$workspace->id.'/'.uniqid().'.bin';
        Storage::disk('documents')->put($path, 'file-bytes');
        $document = $this->document($workspace, $path);
        Storage::disk('documents')->delete($path);

        Sanctum::actingAs($user);
        $response = $this->getJson("/api/documents/{$document->id}/download");

        $response->assertNotFound()
            ->assertJsonPath('message', 'Resource not found.');
        $this->assertArrayNotHasKey('error', $response->json());
    }

    /**
     * The status gate is checked before the new guard and keeps its 409, so a
     * row with no key *and* a non-downloadable status still reads as "not
     * available yet" rather than "no original" — the status is the more
     * actionable answer, and this ordering is what keeps 409 meaning one
     * thing on this endpoint.
     */
    public function test_a_non_downloadable_status_still_answers_409_before_the_guard(): void
    {
        [$user, $workspace] = $this->orgUser();
        $document = $this->document($workspace, null, 'Processing');

        Sanctum::actingAs($user);
        $this->getJson("/api/documents/{$document->id}/download")
            ->assertStatus(409);
    }

    /** A real download is untouched, filename header included. */
    public function test_a_normal_download_still_streams_with_the_right_filename(): void
    {
        [$user, $workspace] = $this->orgUser();
        $path = 'documents/'.$workspace->id.'/'.uniqid().'.bin';
        Storage::disk('documents')->put($path, 'file-bytes');
        $document = $this->document($workspace, $path);

        Sanctum::actingAs($user);
        $response = $this->get("/api/documents/{$document->id}/download");

        $response->assertOk();
        $this->assertStringContainsString(
            'seeded.pdf',
            (string) $response->headers->get('content-disposition'),
        );
    }
}
