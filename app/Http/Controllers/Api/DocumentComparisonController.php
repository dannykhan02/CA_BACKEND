<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentComparisonResource;
use App\Models\DocumentComparison;
use App\Services\DocumentComparisonService;
use App\Services\IntelligenceAccess;
use Illuminate\Http\Request;

class DocumentComparisonController extends Controller
{
    public function __construct(private IntelligenceAccess $access, private DocumentComparisonService $service) {}

    public function index(Request $r)
    {
        $ids = $this->access->documents($r->user())->select('id');
        $q = DocumentComparison::where('workspace_id', $r->user()->current_workspace_id)
            ->whereIn('base_document_id', $ids)->whereIn('compared_document_id', clone $ids)
            ->with(['baseDocument:id,name', 'comparedDocument:id,name']);
        if ($r->filled('matter_id')) {
            $matter = $this->access->matter($r->user(), $r->query('matter_id'));
            $q->whereHas('baseDocument', fn ($q) => $q->where('matter_id', $matter->id))
                ->whereHas('comparedDocument', fn ($q) => $q->where('matter_id', $matter->id));
        }
        if ($r->filled('document_id')) {
            $doc = $this->access->document($r->user(), $r->query('document_id'));
            $q->where(fn ($q) => $q->where('base_document_id', $doc->id)->orWhere('compared_document_id', $doc->id));
        }

        return DocumentComparisonResource::collection($q->latest()->paginate(20));
    }

    public function store(Request $r)
    {
        $data = $r->validate(['include_terms' => 'sometimes|boolean', 'base_document_id' => 'required|uuid', 'compared_document_id' => 'required|uuid|different:base_document_id']);
        $item = $this->service->create($r->user(), $data['base_document_id'], $data['compared_document_id'], $data['include_terms'] ?? false);

        return (new DocumentComparisonResource($item))->response()->setStatusCode(202);
    }

    public function show(Request $r, string $comparison)
    {
        $item = DocumentComparison::where('workspace_id', $this->access->workspace($r->user()))->findOrFail($comparison);
        $this->access->document($r->user(), $item->base_document_id);
        $this->access->document($r->user(), $item->compared_document_id);

        return new DocumentComparisonResource($item->load(['baseDocument:id,name', 'comparedDocument:id,name']));
    }
}
