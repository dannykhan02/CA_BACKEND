<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentChart;
use App\Services\DocumentIntelligenceService;
use App\Services\Intelligence\SourceChartAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentChartImageController extends Controller
{
    public function show(Request $request, Document $document, DocumentChart $chart, SourceChartAccess $access): StreamedResponse
    {
        $this->authorize('view', $document);
        $view = app(DocumentIntelligenceService::class)->publishedView($document);
        $path = $access->path($view, $chart);
        if ($path === null) {
            abort(404);
        }
        $disk = Storage::disk('documents');
        $bytes = $disk->get($path);
        if (! is_string($bytes) || ! hash_equals($chart->source_metadata['sha256'], hash('sha256', $bytes))) {
            abort(404);
        }

        return response()->stream(function () use ($bytes): void { echo $bytes; }, 200, [
            'Content-Type' => $chart->source_metadata['media_type'],
            'Content-Disposition' => 'inline; filename="source-chart-'.$chart->id.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
