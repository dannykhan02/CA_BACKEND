<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentDeadline;
use App\Models\DocumentEntity;
use App\Models\DocumentIntelligenceSummary;
use App\Models\DocumentKpi;
use App\Models\DocumentRisk;
use App\Models\Matter;
use App\Models\ProcessingJob;
use App\Models\TrackedItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class MatterService
{
    public function __construct(private IntelligenceAccess $access) {}

    public function assign(User $user, string $matterId, string $documentId, bool $remove = false): void
    {
        DB::transaction(function () use ($user, $matterId, $documentId, $remove) {
            $matter = $this->access->matter($user, $matterId, true);
            $document = $this->access->document($user, $documentId, true);
            $document = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($document->matter_id) {
                $this->access->matter($user, $document->matter_id, true);
            }
            abort_if($remove && $document->matter_id !== $matter->id, 422, 'Document is not in this Matter.');
            $document->update(['matter_id' => $remove ? null : $matter->id]);
            TrackedItem::where('document_id', $document->id)->update(['matter_id' => $document->matter_id]);
        });
    }

    public function intelligence(User $user, Matter $matter, string $kind)
    {
        $ids = $this->access->documents($user)->where('matter_id', $matter->id)->select('id');
        $model = match ($kind) {
            'summaries' => DocumentIntelligenceSummary::class,
            'risks' => DocumentRisk::class,
            'deadlines', 'obligations' => DocumentDeadline::class,
            'entities' => DocumentEntity::class,
            'kpis' => DocumentKpi::class,
            'tracked' => TrackedItem::class,
            default => abort(422, 'Unknown intelligence section.'),
        };

        return $model::whereIn('document_id', $ids)->with('document:id,name')->orderBy('id');
    }

    public function overview(User $user, Matter $matter): array
    {
        $documents = $this->access->documents($user)->where('matter_id', $matter->id);
        $latestStages = ProcessingJob::whereIn('document_id', (clone $documents)->select('id'))
            ->whereIn('stage', ['entities', 'risks', 'deadlines', 'document_summary'])
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('processing_jobs as newer')
                    ->whereColumn('newer.document_id', 'processing_jobs.document_id')
                    ->whereColumn('newer.stage', 'processing_jobs.stage')
                    ->where(function ($q) {
                        $q->whereColumn('newer.created_at', '>', 'processing_jobs.created_at')
                            ->orWhere(fn ($q) => $q->whereColumn('newer.created_at', 'processing_jobs.created_at')->whereColumn('newer.id', '>', 'processing_jobs.id'));
                    });
            });

        return [
            'failed_stages' => (clone $latestStages)->where('status', 'failed')->count(),
            'pending_stages' => (clone $latestStages)->whereIn('status', ['pending', 'processing'])->count(),
            'documents' => (clone $documents)->count(),
            'risks' => $this->intelligence($user, $matter, 'risks')->where('status', 'open')->count(),
            'obligations' => $this->intelligence($user, $matter, 'obligations')->count(),
            'upcoming' => $this->intelligence($user, $matter, 'deadlines')->whereDate('due_date', '>=', today())->count(),
            'tracked' => $this->intelligence($user, $matter, 'tracked')->where('status', 'open')->count(),
            'failed_documents' => (clone $documents)->where('status', 'Failed')->count(),
            'processing_documents' => (clone $documents)->where('status', 'Processing')->count(),
        ];
    }
}
