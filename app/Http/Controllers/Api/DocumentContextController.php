<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\IntelligenceAccess;
use App\Services\RelatedDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DocumentContextController extends Controller
{
    public function __construct(private IntelligenceAccess $access, private RelatedDocumentService $related) {}

    public function show(Request $r, string $document)
    {
        $doc = $this->access->document($r->user(), $document);

        return response()->json(['matter' => $doc->matter_id ? $this->access->matters($r->user())->find($doc->matter_id) : null,
            'suggestions' => $this->related->suggestions($r->user(), $doc)]);
    }

    public function dismiss(Request $r, string $document, string $related)
    {
        $this->access->document($r->user(), $document);
        $this->access->document($r->user(), $related);
        DB::table('document_suggestion_dismissals')->updateOrInsert([
            'user_id' => $r->user()->id, 'document_id' => $document, 'related_document_id' => $related,
        ], ['created_at' => now(), 'updated_at' => now()]);

        return response()->noContent();
    }
}
