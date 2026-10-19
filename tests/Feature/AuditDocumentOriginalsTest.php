<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `documents:audit-originals` — the reconciliation that was missing during the
 * 2026-10-17 incident.
 *
 * What these tests hold it to: it reports every kind of unresolvable row, and
 * it fails **only** for a live `Ready` document. Getting that boundary wrong
 * in either direction makes it useless — red forever because of six seeded
 * rows nobody will ever fix, or green while a reader cannot open a citation.
 *
 * `Storage::fake('documents')` throughout: the real disk is R2 and CI has no
 * R2 settings.
 */
class AuditDocumentOriginalsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $this->user = $user->fresh();
    }

    private function document(array $attributes = []): Document
    {
        return Document::create(array_merge([
            'workspace_id' => $this->user->current_workspace_id,
            'uploaded_by' => $this->user->id,
            'name' => 'Agreement.pdf',
            'type' => 'PDF',
            'status' => 'Ready',
            'classification' => 'Internal',
            'size_kb' => 12,
            'year' => 2026,
            'file_path' => $this->user->current_workspace_id.'/'.fake()->uuid().'.pdf',
        ], $attributes));
    }

    /** A row whose original really is on the disk. */
    private function stored(array $attributes = []): Document
    {
        $document = $this->document($attributes);
        Storage::disk('documents')->put($document->file_path, '%PDF-1.4 fixture');

        return $document;
    }

    public function test_a_fully_resolving_set_passes_with_a_clear_report(): void
    {
        $this->stored();
        $this->stored(['status' => 'Processing']);

        $this->artisan('documents:audit-originals')
            ->expectsOutputToContain('Rows examined:            2')
            ->expectsOutputToContain('Originals present:        2')
            ->expectsOutputToContain('OK: every Ready document with a recorded original resolves')
            ->assertExitCode(0);
    }

    public function test_a_ready_document_with_a_missing_original_fails_the_command(): void
    {
        $this->stored();
        $missing = $this->document(); // never written to the fake disk

        // Capture once: the id and the full path are printed on the SAME line,
        // and artisan()'s expectsOutputToContain lets one written line satisfy
        // only one expectation, so asserting both that way can never pass.
        $exit = Artisan::call('documents:audit-originals');
        $output = Artisan::output();

        self::assertSame(1, $exit);
        self::assertStringContainsString('Missing, Ready:           1', $output);
        self::assertStringContainsString('MISSING on disk — Ready documents', $output);
        self::assertStringContainsString($missing->id, $output);
        // An operator must be able to copy the full key of a missing original.
        self::assertStringContainsString($missing->file_path, $output);
        self::assertStringContainsString('FAIL: a Ready document has a recorded original that does not resolve.', $output);
        // The hint that would have shortened the incident.
        self::assertStringContainsString('suspect addressing before data loss', $output);
    }

    public function test_a_null_file_path_is_reported_but_does_not_fail(): void
    {
        // The six seeded rows in production: no object because nothing was
        // ever uploaded. Permanently unfixable, so it must not be a failure.
        $this->stored();
        $seeded = $this->document(['file_path' => null]);

        $this->artisan('documents:audit-originals')
            ->expectsOutputToContain('No file_path recorded:    1  (reported, not a failure)')
            ->expectsOutputToContain('No file_path recorded:')
            ->expectsOutputToContain($seeded->id)
            ->expectsOutputToContain('(none)')
            ->expectsOutputToContain('OK: every Ready document with a recorded original resolves')
            ->assertExitCode(0);
    }

    public function test_an_empty_string_file_path_counts_as_no_path_not_as_missing(): void
    {
        $this->document(['file_path' => '   ']);

        $this->artisan('documents:audit-originals')
            ->expectsOutputToContain('No file_path recorded:    1')
            ->expectsOutputToContain('Missing, Ready:           0')
            ->assertExitCode(0);
    }

    public function test_a_trashed_row_with_a_missing_original_is_flagged_but_does_not_fail(): void
    {
        $this->stored();
        $trashed = $this->document();
        $trashed->delete();

        $this->artisan('documents:audit-originals')
            ->expectsOutputToContain('Missing, soft-deleted:    1  (reported, not a failure)')
            ->expectsOutputToContain('MISSING on disk — TRASHED rows')
            ->expectsOutputToContain($trashed->id)
            ->assertExitCode(0);
    }

    public function test_a_failed_zero_byte_upload_is_reported_but_does_not_fail(): void
    {
        // The seventh unresolved production row: a 0 MB Failed upload.
        $this->stored();
        $failed = $this->document(['status' => 'Failed', 'size_kb' => 0]);

        $this->artisan('documents:audit-originals')
            ->expectsOutputToContain('Missing, other status:    1  (reported, not a failure)')
            ->expectsOutputToContain('MISSING on disk — other statuses')
            ->expectsOutputToContain($failed->id)
            ->assertExitCode(0);
    }

    public function test_the_four_kinds_are_counted_separately_in_one_run(): void
    {
        // The production shape: one good row, and one of each thing that is
        // reported without failing, plus the one thing that fails.
        $this->stored();
        $this->document(['file_path' => null]);
        $this->document(['status' => 'Failed']);
        $trashed = $this->document();
        $trashed->delete();
        $this->document(); // Ready and missing

        $this->artisan('documents:audit-originals')
            ->expectsOutputToContain('Rows examined:            5')
            ->expectsOutputToContain('Originals present:        1')
            ->expectsOutputToContain('No file_path recorded:    1')
            ->expectsOutputToContain('Missing, Ready:           1')
            ->expectsOutputToContain('Missing, other status:    1')
            ->expectsOutputToContain('Missing, soft-deleted:    1')
            ->assertExitCode(1);
    }

    public function test_it_prints_the_key_prefix_because_that_was_the_incident(): void
    {
        $this->stored();

        // Storage::fake sets a local disk, so root is a real directory here;
        // what matters is that the line is printed at all, since an
        // unintended prefix is the failure this command exists to surface.
        $this->artisan('documents:audit-originals')
            ->expectsOutputToContain('Key prefix (root):')
            ->expectsOutputToContain('Documents disk:')
            ->assertExitCode(0);
    }

    public function test_the_limit_option_caps_the_listing_and_says_how_many_were_hidden(): void
    {
        $this->document();
        $this->document();
        $this->document();

        $this->artisan('documents:audit-originals', ['--limit' => 2])
            ->expectsOutputToContain('Missing, Ready:           3')
            ->expectsOutputToContain('… and 1 more')
            ->assertExitCode(1);
    }

    public function test_it_writes_nothing_and_removes_nothing(): void
    {
        // The whole point of a read-only command. Run it over a mixed set and
        // the disk must be exactly as it was.
        $kept = $this->stored();
        $this->document();
        $this->document(['file_path' => null]);
        $before = Storage::disk('documents')->allFiles();

        $this->artisan('documents:audit-originals')->assertExitCode(1);

        self::assertSame($before, Storage::disk('documents')->allFiles());
        Storage::disk('documents')->assertExists($kept->file_path);
        self::assertSame('%PDF-1.4 fixture', Storage::disk('documents')->get($kept->file_path));
    }

    public function test_it_chunks_rather_than_loading_every_row(): void
    {
        // A small chunk size over more rows than the chunk: the result must be
        // identical, which is what proves chunkById is wired correctly.
        for ($i = 0; $i < 5; $i++) {
            $this->stored();
        }

        $this->artisan('documents:audit-originals', ['--chunk' => 2])
            ->expectsOutputToContain('Rows examined:            5')
            ->expectsOutputToContain('Originals present:        5')
            ->assertExitCode(0);
    }
}
