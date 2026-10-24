<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\DocumentIntelligenceRevision;
use App\Services\AiCredits\AiCreditAdmission;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Puts documents that were failed by running AI before the customer confirmed a large quote back into the waiting state.
 *
 * Dry-run by default: it only lists what it would change. With --apply each document is changed in its own
 * transaction: status Processing, the "Waiting for your confirmation" stage, the held-insights marker (so confirming
 * runs the analysis), and the candidate revision that the failure closed. Credits are never touched: nothing was
 * reserved, and confirming reserves them as usual. Documents the customer already confirmed are left alone.
 */
class RepairUnconfirmedCreditDocuments extends Command
{
    protected $signature = 'credits:repair-unconfirmed {--apply : Write the changes (default is a dry run)} {--dry-run : List only; this is the default}';

    protected $description = 'Return documents failed by an unconfirmed credit quote to the awaiting-confirmation state.';

    /** Messages the insights stage writes when it fails, which is what credits_not_reserved looked like to the customer. */
    private const FAILURE_MESSAGES = ['Analysis could not be completed. Please try again.', 'AI insights generation failed.'];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply') && ! $this->option('dry-run');
        $changed = 0;
        $rows = [];

        Document::where('ai_pipeline->awaiting_credit_confirmation', true)
            ->where(fn ($q) => $q->where('status', 'Needs Review')
                ->orWhere(fn ($q) => $q->where('status', 'Failed')->whereIn('error_message', self::FAILURE_MESSAGES)))
            ->orderBy('created_at')->select('id')->chunkById(50, function ($documents) use ($apply, &$changed, &$rows) {
                foreach ($documents as $row) {
                    $outcome = $this->repair($row->id, $apply);
                    $rows[] = [$row->id, $outcome];
                    $changed += str_starts_with($outcome, 'repaired') ? 1 : 0;
                }
            });

        if ($rows === []) {
            $this->info('No documents are stuck.');

            return self::SUCCESS;
        }
        $this->table(['Document', $apply ? 'Result' : 'Would do'], $rows);
        $this->info($apply ? "Repaired {$changed} document(s)." : 'Dry run: nothing was changed. Re-run with --apply to write.');

        return self::SUCCESS;
    }

    private function repair(string $id, bool $apply): string
    {
        return DB::transaction(function () use ($id, $apply) {
            $document = Document::whereKey($id)->lockForUpdate()->first();
            $pipeline = $document?->ai_pipeline ?? [];
            if (! $document || ! ($pipeline['awaiting_credit_confirmation'] ?? false)) {
                return 'skipped: not waiting';
            }
            if ($pipeline['credit_quote_confirmed'] ?? false) {
                return 'skipped: already confirmed, resume it with docintel:resume';
            }
            $revision = null;
            if ($document->active_intelligence_revision_id === null && $document->intelligence_generation !== null) {
                $revision = DocumentIntelligenceRevision::where('document_id', $document->id)
                    ->where('generation', $document->intelligence_generation)->where('state', 'failed')
                    ->whereIn('publication_reason', ['analysis_failed', 'analysis_job_failed'])->latest('failed_at')->first();
            }
            $note = $revision ? 'repaired (revision reopened)' : 'repaired';
            if (! $apply) {
                return 'would put back to Processing, awaiting confirmation'.($revision ? ' and reopen its revision' : '');
            }
            if ($revision) {
                $revision->forceFill(['state' => 'candidate', 'failed_at' => null, 'publication_reason' => null])->save();
                $document->active_intelligence_revision_id = $revision->id;
            }
            $pipeline['progress_stage'] = ['key' => AiCreditAdmission::WAITING_STAGE, 'label' => 'Waiting for your confirmation',
                'updated_at' => now()->toIso8601String()];
            $pipeline['insights_held'] = true;
            $document->forceFill(['status' => 'Processing', 'error_message' => null, 'ai_pipeline' => $pipeline])->save();

            return $note;
        });
    }
}
