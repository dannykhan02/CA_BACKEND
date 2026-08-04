<?php

namespace App\Http\Controllers\Api;

use App\Enums\WorkspaceType;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Policies\DocumentPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        // Previously counted across ALL documents regardless of the caller's
        // role — a Viewer could infer the existence/volume of Restricted
        // documents from aggregate counts alone (audit F-High-2). Scoped to
        // classification, but that alone still let any Personal-workspace
        // user see every other Personal user's Public/Internal document
        // counts system-wide, since every signup defaults to a role whose
        // allow-list is ['Public', 'Internal'] with no workspace boundary.
        // workspace_id is now the outer filter, applied before any
        // classification logic, so an Organization Administrator can never
        // see another Organization's counts just because their role matches.
        $query = Document::query()->where('workspace_id', $user->current_workspace_id);

        $isPersonal = $user->currentWorkspace?->type === WorkspaceType::Personal;

        if (! $isPersonal) {
            $allowedClassifications = DocumentPolicy::allowedClassificationsFor($user);
            $query->whereIn('classification', $allowedClassifications);
        }

        $counts = $query
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $total = $counts->sum();

        return response()->json([
            'data' => [
                'total' => $total,
                'ready' => (int) ($counts['Ready'] ?? 0),
                'processing' => (int) ($counts['Processing'] ?? 0),
                'needsReview' => (int) ($counts['Needs Review'] ?? 0),
                'failed' => (int) ($counts['Failed'] ?? 0),
            ],
        ]);
    }
}