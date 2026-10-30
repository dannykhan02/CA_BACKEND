<?php

namespace App\Services\Reports;

use App\Http\Resources\DocumentComparisonResource;
use App\Http\Resources\DocumentIntelligenceResource;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\DocumentComparison;
use App\Models\DocumentIntelligenceRevision;
use App\Models\Matter;
use App\Services\DocumentIntelligenceService;
use App\Services\IntelligenceAccess;
use App\Services\MatterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Materializes each report from one database view before DOCX generation. */
class ReportSnapshotService
{
    private const MAX_DOCUMENTS = 500;

    private const MAX_ROWS_PER_SECTION = 10000;

    public function __construct(private IntelligenceAccess $access, private MatterService $matters,
        private DocumentIntelligenceService $intelligence) {}

    public function document(Request $request, Document $document): array
    {
        return $this->snapshot(function () use ($request, $document): array {
            $current = $this->access->document($request->user(), $document->id);
            $view = $this->intelligence->loadIntelligence($current);
            $resource = (new DocumentResource($view))->resolve($request);
            $intelligence = (new DocumentIntelligenceResource($view))->resolve($request);

            return ['document' => $resource, 'intelligence' => $intelligence,
                'intelligenceRevisionId' => $view->published_intelligence_revision_id,
                'complete' => $view->status === 'Ready'
                    && ! ($intelligence['processingDetails']['partial'] ?? false),
                'limitations' => $view->published_intelligence_revision_id === null
                    ? ['Legacy intelligence has no immutable revision identity.'] : []];
        });
    }

    public function matter(Request $request, Matter $matter): array
    {
        return $this->snapshot(function () use ($request, $matter): array {
            $user = $request->user();
            $current = $this->access->matter($user, $matter->id);
            $documents = $this->access->documents($user)->where('matter_id', $current->id)
                ->orderBy('id')->limit(self::MAX_DOCUMENTS + 1)->get();
            abort_if($documents->count() > self::MAX_DOCUMENTS, 422, 'Matter report exceeds the document limit.');
            $ids = $documents->pluck('id');
            $sections = [];
            foreach (['summaries', 'entities', 'obligations', 'deadlines', 'risks', 'kpis', 'tracked'] as $kind) {
                $rows = $this->matters->intelligence($user, $current, $kind)
                    ->limit(self::MAX_ROWS_PER_SECTION + 1)->get();
                abort_if($rows->count() > self::MAX_ROWS_PER_SECTION, 422,
                    "Matter report exceeds the {$kind} limit.");
                $sections[$kind] = $rows->toArray();
            }
            $visibleIds = $this->access->documents($user)->select('id');
            $comparisons = DocumentComparison::where('workspace_id', $current->workspace_id)
                ->where('matter_id', $current->id)
                ->whereIn('base_document_id', $visibleIds)->whereIn('compared_document_id', clone $visibleIds)
                ->with(['baseDocument', 'comparedDocument'])->orderBy('id')
                ->limit(self::MAX_ROWS_PER_SECTION + 1)->get();
            abort_if($comparisons->count() > self::MAX_ROWS_PER_SECTION, 422,
                'Matter report exceeds the comparison limit.');

            $revisions = DocumentIntelligenceRevision::whereIn('id', $documents->pluck('published_intelligence_revision_id')->filter())
                ->where('state', 'published')->get()->keyBy('id');
            $unversioned = $documents->filter(fn (Document $doc) => $doc->published_intelligence_revision_id === null);
            $unavailable = $documents->filter(fn (Document $doc) => $doc->published_intelligence_revision_id === null
                ? ((int) $doc->intelligence_generation > 0 || $doc->status !== 'Ready')
                : ! $revisions->has($doc->published_intelligence_revision_id));
            $partial = $documents->filter(function (Document $doc) use ($revisions): bool {
                $pipeline = $doc->published_intelligence_revision_id
                    ? $revisions->get($doc->published_intelligence_revision_id)?->ai_pipeline
                    : $doc->ai_pipeline;

                return (bool) ($pipeline['partial'] ?? false);
            });

            return ['matter' => $current->toArray(),
                'documents' => $documents->map(fn (Document $doc) => $doc->only(['id', 'name', 'status']))->all(),
                'sections' => $sections,
                'comparisons' => DocumentComparisonResource::collection($comparisons)->resolve($request),
                'revisionSnapshot' => $documents->mapWithKeys(fn (Document $doc) => [$doc->id => $doc->published_intelligence_revision_id])->all(),
                'complete' => $unavailable->isEmpty() && $partial->isEmpty(),
                'limitations' => array_values(array_filter([
                    $comparisons->contains(fn ($comparison) => ! $ids->contains($comparison->base_document_id) || ! $ids->contains($comparison->compared_document_id))
                        ? 'Historical comparisons may cite authorized documents that have moved out of this Matter.' : null,
                    $unversioned->isNotEmpty() ? 'Some documents have legacy intelligence without immutable revision identity.' : null,
                    $unavailable->isNotEmpty() ? 'Some documents have no published intelligence.' : null,
                    $partial->isNotEmpty() ? 'Some document analysis is partial.' : null,
                ]))];
        });
    }

    private function snapshot(callable $build): array
    {
        return DB::transaction(function () use ($build): array {
            // PostgreSQL's default READ COMMITTED could mix publications
            // between sections. Tests using RefreshDatabase already hold an
            // outer transaction; production requests start at level one.
            if (DB::getDriverName() === 'pgsql' && DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }

            // Resolve nested Laravel resources while the snapshot is open.
            return json_decode(json_encode($build(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        });
    }
}
