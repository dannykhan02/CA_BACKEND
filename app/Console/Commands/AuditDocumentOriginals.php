<?php

namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Read-only reconciliation of document rows against the `documents` disk.
 *
 * Written after the 2026-10-17 incident, where a `root` added to an s3-driver
 * disk became an object-key prefix and 79 of 86 documents stopped resolving
 * while every object sat untouched in the bucket. Nothing in the system
 * noticed: the first signal was a reader clicking a citation and getting a
 * 404. This command is the check that was missing — run after the bad deploy
 * it would have reported 79 missing originals in under a minute.
 *
 * **It never writes, deletes, copies or reads file contents.** It asks the
 * disk one question per row, `exists()`, and prints identifiers.
 *
 * Exit code is deliberately narrow: non-zero **only** when a live `Ready`
 * document's recorded original is missing, because that is the only case
 * where a reader is being shown a citation whose source cannot be opened.
 * Rows with no `file_path` (seeded or never-uploaded) and failed uploads are
 * reported and do not fail the command — otherwise the signal would be
 * permanently red for reasons nobody intends to fix.
 */
class AuditDocumentOriginals extends Command
{
    protected $signature = 'documents:audit-originals
        {--limit=20 : How many offenders of each kind to print}
        {--chunk=200 : Rows per database chunk}';

    protected $description = 'Read-only: report document rows whose original does not resolve on the documents disk.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $chunk = max(1, min(1000, (int) $this->option('chunk')));
        $disk = Storage::disk('documents');

        $counts = ['total' => 0, 'present' => 0, 'nullPath' => 0,
            'missingReady' => 0, 'missingOther' => 0, 'missingTrashed' => 0];
        /** @var array<string, list<array{id: string, created_at: string, status: string, file_path: string}>> */
        $offenders = ['nullPath' => [], 'missingReady' => [], 'missingOther' => [], 'missingTrashed' => []];

        // withTrashed: a soft-deleted row still owns its object, and whether
        // its original survived is worth knowing without it failing the run.
        //
        // No `orderBy` of our own. `chunkById` pages with `where id > lastId`
        // and `removeExistingOrdersFor` strips only the id column's order, so
        // an added `orderBy('created_at')` would survive, take precedence, and
        // let the id predicate skip rows whose id is higher but whose
        // created_at is earlier. An audit that silently misses rows is worse
        // than no audit, and the report does not depend on row order.
        Document::withTrashed()
            ->select(['id', 'created_at', 'status', 'file_path', 'deleted_at'])
            ->chunkById($chunk, function ($documents) use ($disk, $limit, &$counts, &$offenders): void {
                foreach ($documents as $document) {
                    $counts['total']++;
                    $trashed = $document->deleted_at !== null;
                    $path = $document->file_path;

                    // Same predicate as the download guard and the API
                    // resources, via the one model method, so the audit can
                    // never classify a row as "no original" that the endpoint
                    // would still try to stream (or the reverse).
                    if (! $document->hasRecordedOriginal()) {
                        $counts['nullPath']++;
                        $this->remember($offenders['nullPath'], $document, '(none)', $limit);

                        continue;
                    }

                    if ($disk->exists($path)) {
                        $counts['present']++;

                        continue;
                    }

                    $bucket = $trashed ? 'missingTrashed'
                        : ($document->status === 'Ready' ? 'missingReady' : 'missingOther');
                    $counts[$bucket]++;
                    $this->remember($offenders[$bucket], $document, $path, $limit);
                }
            });

        $this->report($counts, $offenders, $limit);

        // The one condition worth a red build.
        return $counts['missingReady'] > 0 ? 1 : 0;
    }

    /**
     * @param  list<array{id: string, created_at: string, status: string, file_path: string}>  $into
     */
    private function remember(array &$into, Document $document, string $path, int $limit): void
    {
        if (count($into) >= $limit) {
            return;
        }
        $into[] = [
            'id' => (string) $document->id,
            'created_at' => (string) $document->created_at,
            'status' => (string) $document->status,
            'file_path' => $path,
        ];
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, list<array<string, string>>>  $offenders
     */
    private function report(array $counts, array $offenders, int $limit): void
    {
        $this->line('Documents disk: '.config('filesystems.disks.documents.driver').' driver');
        // Printed because an unintended prefix is exactly what caused the
        // incident this command exists for. It is a key prefix, never a secret.
        $root = config('filesystems.disks.documents.root');
        $this->line('Key prefix (root): '.($root === null || $root === '' ? '(none)' : (string) $root));
        $this->newLine();

        $this->line('Rows examined:            '.$counts['total']);
        $this->line('Originals present:        '.$counts['present']);
        $this->line('No file_path recorded:    '.$counts['nullPath'].'  (reported, not a failure)');
        $this->line('Missing, Ready:           '.$counts['missingReady']);
        $this->line('Missing, other status:    '.$counts['missingOther'].'  (reported, not a failure)');
        $this->line('Missing, soft-deleted:    '.$counts['missingTrashed'].'  (reported, not a failure)');

        foreach ([
            'missingReady' => 'MISSING on disk — Ready documents (a reader cannot open these)',
            'missingOther' => 'MISSING on disk — other statuses',
            'missingTrashed' => 'MISSING on disk — TRASHED rows',
            'nullPath' => 'No file_path recorded',
        ] as $key => $heading) {
            if ($offenders[$key] === []) {
                continue;
            }
            $this->newLine();
            $this->line($heading.':');
            foreach ($offenders[$key] as $row) {
                $this->line(sprintf('  %s  %s  %-12s  %s',
                    $row['id'], $row['created_at'], $row['status'], $row['file_path']));
            }
            if ($counts[$key] > count($offenders[$key])) {
                $this->line(sprintf('  … and %d more (raise --limit above %d to see them)',
                    $counts[$key] - count($offenders[$key]), $limit));
            }
        }

        $this->newLine();
        if ($counts['missingReady'] > 0) {
            $this->line('FAIL: a Ready document has a recorded original that does not resolve.');
            $this->line('If many rows are affected at once, suspect addressing before data loss:');
            $this->line('check the key prefix above, then list the bucket for the same keys.');

            return;
        }
        $this->line('OK: every Ready document with a recorded original resolves on the disk.');
    }
}
