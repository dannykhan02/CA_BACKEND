<?php

namespace App\Http\Controllers\Api;

use App\Enums\WorkspaceType;
use App\Http\Controllers\Controller;
use App\Models\DocumentKpi;
use App\Policies\DocumentPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkspaceInsightsController extends Controller
{
    /**
     * Cross-document KPI trends, grouped by exact label text (case/whitespace
     * normalized only — no semantic/fuzzy matching; see v2 note below).
     *
     * Each Claude call in GenerateInsightsJob writes KPI labels independently
     * per document, so the same real-world metric can surface under slightly
     * different label text across reports (e.g. "Internal Service Charter
     * Performance" vs "Internal Charter Performance"). Grouping only on
     * identical (normalized) text means a trend can legitimately omit some
     * uploaded reports — reportsIncluded / reportsTotal makes that explicit
     * to the frontend instead of silently implying full coverage.
     *
     * v2 (not built here, deliberately): use the Voyage embeddings already
     * generated per-document (DocumentEmbedding) to compute label similarity
     * and merge near-duplicate labels. Deferred because a false semantic
     * match (merging two actually-different metrics) is worse than a false
     * non-match (an honestly incomplete trend) — same prove-the-need-first
     * principle used elsewhere in this project. This endpoint's response
     * shape (reportsIncluded/reportsTotal) is the extension point: a v2
     * grouping strategy only needs to change how rows are bucketed, not the
     * response contract.
     */
    public function trends(Request $request): JsonResponse
    {
        $user = $request->user();

        $isPersonal = $user->currentWorkspace?->type === WorkspaceType::Personal;

        $baseQuery = DocumentKpi::query()
            ->where('document_kpis.workspace_id', $user->current_workspace_id);

        if (! $isPersonal) {
            $allowedClassifications = DocumentPolicy::allowedClassificationsFor($user);
            $baseQuery->whereHas('document', function ($query) use ($allowedClassifications) {
                $query->whereIn('classification', $allowedClassifications);
            });
        }

        // Distinct documents in this workspace (post-classification-filter)
        // that have any KPI rows at all — the denominator for "N of M
        // reports" honesty. Not every uploaded document necessarily reaches
        // this stage (Failed/Needs Review documents never got insights), so
        // "reports" here means "reports insights were generated for", not
        // every row in the documents table.
        $reportsTotal = (clone $baseQuery)->distinct()->count('document_id');

        $rows = (clone $baseQuery)
            ->select([
                DB::raw('TRIM(LOWER(label)) as normalized_label'),
                'label',
                'document_id',
                'value',
                'value_numeric',
                'unit',
                'trend',
                'trend_value',
                'document_kpis.created_at',
            ])
            ->orderBy('document_kpis.created_at')
            ->get();

        $groups = $rows->groupBy('normalized_label');

        $trends = $groups->map(function ($groupRows) use ($reportsTotal) {
            // Display label: use the most recent report's exact casing/
            // spacing rather than an arbitrarily-picked or synthesized one.
            $displayLabel = $groupRows->last()->label;

            $reportsIncluded = $groupRows->pluck('document_id')->unique()->count();

            return [
                'label' => $displayLabel,
                'reportsIncluded' => $reportsIncluded,
                'reportsTotal' => $reportsTotal,
                'isComplete' => $reportsIncluded === $reportsTotal,
                'points' => $groupRows->map(fn ($row) => [
                    'documentId' => $row->document_id,
                    'value' => $row->value,
                    'valueNumeric' => $row->value_numeric,
                    'unit' => $row->unit,
                    'trend' => $row->trend,
                    'trendValue' => $row->trend_value,
                    'recordedAt' => $row->created_at,
                ])->values(),
            ];
        })->values();

        return response()->json([
            'data' => [
                'reportsTotal' => $reportsTotal,
                'trends' => $trends,
            ],
        ]);
    }
}
