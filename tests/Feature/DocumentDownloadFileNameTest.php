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
 * The name a downloaded document is given.
 *
 * The reported defect: a file uploaded as `scan.jpeg` has type JPG, and the
 * controller appended the type's single extension without recognising that
 * the name already ended in a valid one — so the reader was handed
 * `scan.jpeg.jpg`.
 *
 * Note what the fix could *not* be. The type map listed only PDF, DOCX and
 * XLSX, and fell back to `strtolower($document->type)`, which for JPG already
 * produced exactly `jpg`. Adding `'JPG' => 'jpg'` would therefore have
 * changed nothing at all. The bug was never the missing entry — it was
 * comparing the name against one spelling when a type can have two.
 */
class DocumentDownloadFileNameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    /** @return array{0: User, 1: Workspace} */
    private function orgUser(string $role = 'Administrator'): array
    {
        $user = User::factory()->create(['role' => $role]);

        $workspace = Workspace::create([
            'type' => WorkspaceType::Organization,
            'name' => 'Org ' . $user->id,
        ]);

        WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $role,
            'joined_at' => now(),
        ]);

        $user->forceFill(['current_workspace_id' => $workspace->id])->save();

        return [$user, $workspace];
    }

    private function readyDocument(Workspace $workspace, string $name, string $type): Document
    {
        $disk = Storage::disk('documents');
        $path = 'documents/' . $workspace->id . '/' . uniqid() . '.bin';
        $disk->put($path, 'file-bytes');

        return Document::create([
            'name' => $name,
            'type' => $type,
            'size_kb' => 1,
            'status' => 'Ready',
            'classification' => 'Internal',
            'year' => 2026,
            'workspace_id' => $workspace->id,
            'uploaded_by' => User::factory()->create()->id,
            'last_updated_by' => User::factory()->create()->id,
            'file_path' => $path,
        ]);
    }

    /** The filename the browser is told to save, from Content-Disposition. */
    private function downloadedName(User $user, Document $document): string
    {
        Sanctum::actingAs($user);
        $response = $this->get("/api/documents/{$document->id}/download");
        $response->assertOk();

        $disposition = $response->headers->get('content-disposition') ?? '';
        $this->assertNotSame('', $disposition, 'no Content-Disposition header');

        if (preg_match('/filename="([^"]+)"/', $disposition, $matches) === 1) {
            return $matches[1];
        }
        if (preg_match("/filename\*=UTF-8''([^;]+)/", $disposition, $matches) === 1) {
            return rawurldecode($matches[1]);
        }

        $this->fail("could not read a filename from: {$disposition}");
    }

    public function test_a_jpeg_named_file_is_not_double_suffixed_with_jpg(): void
    {
        [$user, $workspace] = $this->orgUser();
        $document = $this->readyDocument($workspace, 'scan.jpeg', 'JPG');

        // The whole point: `.jpeg` is already a correct spelling for JPG.
        $this->assertSame('scan.jpeg', $this->downloadedName($user, $document));
    }

    public function test_a_jpg_named_file_keeps_its_own_spelling(): void
    {
        [$user, $workspace] = $this->orgUser();
        $document = $this->readyDocument($workspace, 'scan.jpg', 'JPG');

        $this->assertSame('scan.jpg', $this->downloadedName($user, $document));
    }

    public function test_an_extensionless_jpg_gets_the_canonical_extension(): void
    {
        [$user, $workspace] = $this->orgUser();
        $document = $this->readyDocument($workspace, 'scan', 'JPG');

        // The first entry in the map is the canonical spelling.
        $this->assertSame('scan.jpg', $this->downloadedName($user, $document));
    }

    public function test_a_png_keeps_its_extension_and_gains_one_when_absent(): void
    {
        [$user, $workspace] = $this->orgUser();

        $named = $this->readyDocument($workspace, 'chart.png', 'PNG');
        $this->assertSame('chart.png', $this->downloadedName($user, $named));

        $bare = $this->readyDocument($workspace, 'chart', 'PNG');
        $this->assertSame('chart.png', $this->downloadedName($user, $bare));
    }

    public function test_an_uppercase_extension_is_recognised_rather_than_duplicated(): void
    {
        [$user, $workspace] = $this->orgUser();
        $document = $this->readyDocument($workspace, 'SCAN.JPEG', 'JPG');

        // The comparison lowercases the name; the stored display name is kept
        // exactly as the user gave it.
        $this->assertSame('SCAN.JPEG', $this->downloadedName($user, $document));
    }

    public function test_the_types_that_already_worked_still_work(): void
    {
        [$user, $workspace] = $this->orgUser();

        foreach ([['report.pdf', 'PDF'], ['notes.docx', 'DOCX'], ['model.xlsx', 'XLSX']] as [$name, $type]) {
            $document = $this->readyDocument($workspace, $name, $type);
            $this->assertSame($name, $this->downloadedName($user, $document));
        }

        $bare = $this->readyDocument($workspace, 'report', 'PDF');
        $this->assertSame('report.pdf', $this->downloadedName($user, $bare));
    }

    public function test_a_type_with_no_map_entry_still_gets_one_extension_only(): void
    {
        [$user, $workspace] = $this->orgUser();
        // TIFF is in the DB check constraint but disabled in config; if it is
        // ever enabled before the map is updated, the fallback must still not
        // double-suffix a name that already carries the extension.
        $document = $this->readyDocument($workspace, 'plate.tiff', 'TIFF');

        // The fallback knows only `tiff` (lowercased type), which this name
        // already ends with, so nothing is appended.
        $this->assertSame('plate.tiff', $this->downloadedName($user, $document));
    }
}
