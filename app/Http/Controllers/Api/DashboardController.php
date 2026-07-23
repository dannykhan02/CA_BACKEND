<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function summary(): JsonResponse
    {
        $counts = Document::query()
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
