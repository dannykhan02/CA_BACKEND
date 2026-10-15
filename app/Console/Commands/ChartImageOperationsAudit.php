<?php

namespace App\Console\Commands;

use App\Jobs\PurgeDocumentChartImagesJob;
use App\Models\Document;
use App\Models\DocumentChart;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\FileAttributes;

/** Read-only inventory. Candidate paths are never deleted by this command. */
class ChartImageOperationsAudit extends Command
{
    protected $signature = 'chart-images:audit
        {--workspace= : Workspace UUID to inspect}
        {--document= : Optional document UUID within the workspace}
        {--min-age-hours=24 : Exclude recent or in-flight image writes}
        {--limit=100 : Maximum candidate rows to print}';

    protected $description = 'Report failed purge jobs and potential orphan chart images without modifying storage.';

    public function handle(): int
    {
        $workspace = $this->option('workspace');
        $document = $this->option('document');
        $minimumAge = filter_var($this->option('min-age-hours'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! is_string($workspace) || ! Str::isUuid($workspace)
            || ($document !== null && (! is_string($document) || ! Str::isUuid($document)))
            || $minimumAge === false || $minimumAge < 0 || $limit === false || $limit < 1 || $limit > 1000) {
            $this->error('Provide a workspace UUID, optional document UUID, nonnegative minimum age, and limit 1–1000.');

            return self::FAILURE;
        }

        $prefix = 'chart-visuals/'.$workspace.($document ? '/'.$document : '');
        $failed = DB::table('failed_jobs')->where('payload', 'like', '%'.class_basename(PurgeDocumentChartImagesJob::class).'%')->count();
        $this->line("failed_purge_jobs={$failed} (inspect Horizon or queue:failed; failed rows prune after 30 days)");
        $this->line("scope={$prefix} mode=read-only min_age_hours={$minimumAge}");
        $candidates = 0;
        $unknownAge = 0;
        try {
            foreach (Storage::disk('documents')->getDriver()->listContents($prefix, true) as $item) {
                if (! $item instanceof FileAttributes) continue;
                $path = $item->path();
                if (! str_starts_with($path, $prefix.'/')) continue;
                $modified = $item->lastModified();
                if ($modified === null) {
                    $unknownAge++;
                    continue;
                }
                if ($modified > now()->subHours($minimumAge)->timestamp) continue;
                if (DocumentChart::where('source_metadata->path', $path)->exists()) continue;

                $parts = explode('/', $path);
                if (count($parts) !== 5 || $parts[0] !== 'chart-visuals' || $parts[1] !== $workspace
                    || ! Str::isUuid($parts[2]) || ($document !== null && $parts[2] !== $document)
                    || ($parts[3] !== 'legacy' && ! Str::isUuid($parts[3]))
                    || ! preg_match('/^[a-f0-9]{64}$/', $parts[4])) {
                    $this->warn("unclassified_key={$path}");
                    continue;
                }
                $documentExists = Document::withTrashed()->whereKey($parts[2])
                    ->where('workspace_id', $workspace)->exists();
                $reason = $documentExists ? 'unreferenced_document_image' : 'document_missing';
                $this->line("candidate={$path} reason={$reason}");
                if (++$candidates >= $limit) break;
            }
        } catch (\Throwable $error) {
            $this->error('Storage inventory failed ('.$error::class.'); no objects were changed.');

            return self::FAILURE;
        }
        $this->info("candidates_shown={$candidates} unknown_age_skipped={$unknownAge} deletion=none");

        return self::SUCCESS;
    }
}
