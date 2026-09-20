<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentRiskResource;
use App\Services\AuditLogger;
use App\Services\IntelligenceAccess;
use Illuminate\Http\Request;

class DocumentRiskReviewController extends Controller
{
    public function update(Request $request, string $document, string $risk)
    {
        $doc = app(IntelligenceAccess::class)->document($request->user(), $document, true);
        $item = $doc->risks()->findOrFail($risk);
        $data = $request->validate(['status' => 'required|in:open,mitigated,closed']);
        $item->update($data);
        app(AuditLogger::class)->log($request->user(), 'document.risk_reviewed', $doc, ['risk_id' => $item->id, 'status' => $data['status']]);

        return new DocumentRiskResource($item);
    }
}
