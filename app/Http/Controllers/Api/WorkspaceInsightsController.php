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
     * Canonical identity groups resolved observations. Unresolved history keeps
     * the existing lower/trim label grouping in a separate key namespace; it is
     * never silently attached to a resolved series. Coverage counts documents.
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
                'kpi_definition_id',
                'period',
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

        $groups = $rows->groupBy(fn (DocumentKpi $row) => $row->identityKey($row->normalized_label));

        $trends = $groups->map(function ($groupRows) use ($reportsTotal) {
            // Display label: use the most recent report's exact casing/
            // spacing rather than an arbitrarily-picked or synthesized one.
            $displayLabel = $groupRows->last()->label;

            $reportsIncluded = $groupRows->pluck('document_id')->unique()->count();

            return [
                'label' => $displayLabel,
                'kpiDefinitionId' => $groupRows->first()->kpi_definition_id,
                'reportsIncluded' => $reportsIncluded,
                'reportsTotal' => $reportsTotal,
                'isComplete' => $reportsIncluded === $reportsTotal,
                'points' => $groupRows->map(fn ($row) => [
                    'documentId' => $row->document_id,
                    'label' => $row->label,
                    'period' => $row->period,
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
