<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentRiskResource;
use App\Services\AuditLogger;
use App\Services\DocumentIntelligenceService;
use App\Services\IntelligenceAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DocumentRiskReviewController extends Controller
{
    public function update(Request $request, string $document, string $risk)
    {
        return DB::transaction(function () use ($request, $document, $risk) {
            $doc = app(IntelligenceAccess::class)->document($request->user(), $document, true);
            $doc = app(IntelligenceAccess::class)->documents($request->user())->whereKey($doc->id)->lockForUpdate()->firstOrFail();
            $doc = app(DocumentIntelligenceService::class)->publishedView($doc);
            $data = $request->validate(['status' => 'required|in:open,mitigated,closed',
                'expected_status' => 'sometimes|string', 'intelligence_revision_id' => 'nullable|uuid']);
            abort_if(isset($data['intelligence_revision_id']) && $data['intelligence_revision_id'] !== $doc->published_intelligence_revision_id, 409, 'The document revision changed. Refresh before reviewing.');
            $item = $doc->risks()->lockForUpdate()->findOrFail($risk);
            abort_if(isset($data['expected_status']) && $item->status !== $data['expected_status'], 409, 'This risk changed elsewhere. Refresh before reviewing.');
            $item->update(['status' => $data['status']]);
            app(AuditLogger::class)->log($request->user(), 'document.risk_reviewed', $doc, ['risk_id' => $item->id, 'status' => $data['status']]);

            return new DocumentRiskResource($item);
        });
    }
}
