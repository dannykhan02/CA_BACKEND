<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocumentRelationship;
use App\Services\IntelligenceAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DocumentRelationshipController extends Controller
{
    public function __construct(private IntelligenceAccess $access) {}

    public function index(Request $r)
    {
        $ids = $this->access->documents($r->user())->select('id');
        $q = DocumentRelationship::where('workspace_id', $this->access->workspace($r->user()))
            ->whereIn('from_document_id', $ids)->whereIn('to_document_id', clone $ids)
            ->with(['fromDocument:id,name', 'toDocument:id,name']);
        if ($r->filled('document_id')) {
            $doc = $this->access->document($r->user(), $r->query('document_id'));
            $q->where(fn ($q) => $q->where('from_document_id', $doc->id)->orWhere('to_document_id', $doc->id));
        }
        if ($r->filled('matter_id')) {
            $matter = $this->access->matter($r->user(), $r->query('matter_id'));
            $q->whereHas('fromDocument', fn ($q) => $q->where('matter_id', $matter->id))
                ->whereHas('toDocument', fn ($q) => $q->where('matter_id', $matter->id));
        }

        return $q->latest()->paginate(30);
    }

    public function store(Request $r)
    {
        $data = $r->validate(['from_document_id' => 'required|uuid', 'to_document_id' => 'required|uuid|different:from_document_id', 'relationship_type' => 'required|string|max:80|regex:/^[a-z][a-z0-9_]*$/', 'note' => 'nullable|string|max:5000']);
        $this->access->document($r->user(), $data['from_document_id'], true);
        $this->access->document($r->user(), $data['to_document_id'], true);
        $item = DocumentRelationship::firstOrCreate(
            array_intersect_key($data, array_flip(['from_document_id', 'to_document_id', 'relationship_type'])),
            $data + ['workspace_id' => $r->user()->current_workspace_id, 'created_by' => $r->user()->id]
        );

        return response()->json(['data' => $item], $item->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $r, string $relationship)
    {
        $item = $this->find($r, $relationship);
        $data = $r->validate([
            'relationship_type' => ['required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('document_relationships')->where('from_document_id', $item->from_document_id)->where('to_document_id', $item->to_document_id)->ignore($item->id)],
            'note' => 'nullable|string|max:5000',
        ]);
        $item->update($data);

        return response()->json(['data' => $item]);
    }

    public function destroy(Request $r, string $relationship)
    {
        $this->find($r, $relationship)->delete();

        return response()->noContent();
    }

    private function find(Request $r, string $id): DocumentRelationship
    {
        $item = DocumentRelationship::where('workspace_id', $this->access->workspace($r->user(), true))->findOrFail($id);
        $this->access->document($r->user(), $item->from_document_id, true);
        $this->access->document($r->user(), $item->to_document_id, true);

        return $item;
    }
}
