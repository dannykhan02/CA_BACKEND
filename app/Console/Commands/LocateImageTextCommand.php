<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\Intelligence\ImageWordBoxStore;
use App\Services\Ocr\ImageTextLocator;
use App\Services\Ocr\TesseractBinary;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Backfills word boxes for image documents that were analysed before the
 * 'image_text_location' stage existed.
 *
 * Dry run is the default and --force is required to write anything, because
 * this reads every selected image off object storage and runs OCR on it: the
 * safe mistake is to list what would happen, not to start a backfill nobody
 * asked for. It only ever writes word boxes — it cannot change extracted text,
 * analysis, document status or credits.
 */
class LocateImageTextCommand extends Command
{
    protected $signature = 'documents:ocr-locate-images
        {--force : Write word boxes. Without this the command is a dry run and writes nothing}
        {--limit=25 : Maximum documents to process}
        {--timeout=45 : Per-document OCR timeout in seconds}
        {--document= : Restrict to one document id}
        {--workspace= : Restrict to one workspace id}
        {--refresh : Include images that already have stored word boxes}';

    protected $description = 'Locate words in JPG/PNG documents and store their boxes (dry run by default)';

    public function handle(ImageTextLocator $locator, TesseractBinary $binary): int
    {
        $write = (bool) $this->option('force');
        $limit = max(1, (int) $this->option('limit'));
        $timeout = max(1, (int) $this->option('timeout'));

        if (! $locator->enabled()) {
            $this->error('ocr.image_location.enabled is false. Nothing to do.');

            return self::FAILURE;
        }
        if (! $binary->available()) {
            $this->error('The tesseract binary is not available at '.$binary->path().'.');

            return self::FAILURE;
        }
        $this->line($write
            ? '<comment>WRITING</comment> word boxes for up to '.$limit.' document(s).'
            : '<info>DRY RUN</info> — running OCR to report what would be stored, writing nothing. Pass --force to write.');

        $documents = $this->query()->limit($limit)->get();
        if ($documents->isEmpty()) {
            $this->info('No eligible image documents found.');

            return self::SUCCESS;
        }

        $rows = [];
        $located = 0;
        $skipped = 0;
        $words = 0;
        $dropped = 0;
        foreach ($documents as $document) {
            $result = $locator->locate($document, persist: $write, timeoutSeconds: $timeout);
            $result->located ? $located++ : $skipped++;
            $words += $result->wordsStored;
            $dropped += $result->wordsDropped;
            $rows[] = [
                $document->id,
                $document->type,
                $result->located ? 'located' : 'none',
                $result->wordsStored,
                $result->wordsDropped,
                $result->width === null ? '-' : $result->width.'x'.$result->height,
                $result->exifOrientation,
                $result->persisted ? 'yes' : 'no',
                $result->reason ?? '-',
            ];
        }

        $this->table(['document', 'type', 'outcome', 'words', 'dropped below threshold',
            'oriented size', 'exif', 'written', 'reason'], $rows);
        $this->info(sprintf(
            '%d document(s): %d located, %d without boxes. %d words kept, %d dropped below confidence %d. %s',
            $documents->count(), $located, $skipped, $words, $dropped,
            (int) config('ocr.image_location.min_word_confidence'),
            $write ? 'Boxes were written.' : 'Nothing was written (dry run).',
        ));

        return self::SUCCESS;
    }

    private function query(): Builder
    {
        $query = Document::query()
            ->whereIn('type', ['JPG', 'PNG'])
            ->whereIn('status', ['Ready', 'Needs Review'])
            ->whereNotNull('file_path')
            ->whereNotNull('extracted_text')
            ->where('extracted_text', '<>', '')
            ->orderBy('created_at');

        if ($id = $this->option('document')) {
            $query->whereKey($id);
        }
        if ($workspace = $this->option('workspace')) {
            $query->where('workspace_id', $workspace);
        }
        if (! $this->option('refresh')) {
            // Skip images whose boxes already match their current text revision.
            $query->whereNotExists(function ($sub): void {
                $sub->select(DB::raw(1))->from('source_provenance_sets')
                    ->whereColumn('source_provenance_sets.document_id', 'documents.id')
                    ->where('pipeline_key', ImageWordBoxStore::PIPELINE_KEY)
                    ->where('active', true)
                    ->whereRaw("source_revision = encode(sha256(convert_to(documents.extracted_text, 'UTF8')), 'hex')");
            });
        }

        return $query;
    }
}
