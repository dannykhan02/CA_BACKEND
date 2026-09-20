<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Matter;
use App\Services\AuditLogger;
use App\Services\IntelligenceAccess;
use App\Services\MatterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MatterController extends Controller
{
    public function __construct(private IntelligenceAccess $access, private MatterService $service) {}

    public function index(Request $r)
    {
        return $this->access->matters($r->user())->latest()->paginate(20);
    }

    public function store(Request $r)
    {
        $workspace = $this->access->workspace($r->user(), true);

        return response()->json(['data' => Matter::create($this->validated($r) + ['workspace_id' => $workspace, 'created_by' => $r->user()->id])], 201);
    }

    public function show(Request $r, string $matter)
    {
        $item = $this->access->matter($r->user(), $matter);
        $docs = $this->access->documents($r->user())->where('matter_id', $item->id)
            ->select(['id', 'name', 'type', 'status', 'classification', 'workspace_id', 'uploaded_by', 'created_at'])->latest()->paginate(20);

        return response()->json(['data' => $item, 'overview' => $this->service->overview($r->user(), $item), 'documents' => $docs]);
    }

    public function update(Request $r, string $matter)
    {
        $item = $this->access->matter($r->user(), $matter, true);
        $item->update($this->validated($r));

        return response()->json(['data' => $item]);
    }

    public function destroy(Request $r, string $matter)
    {
        $this->access->matter($r->user(), $matter, true)->delete();

        return response()->noContent();
    }

    public function assign(Request $r, string $matter, string $document)
    {
        $data = $r->validate(['related_document_id' => 'nullable|uuid']);
        DB::transaction(function () use ($r, $matter, $document, $data) {
            $this->service->assign($r->user(), $matter, $document, $r->isMethod('delete'));
            if (! $r->isMethod('delete') && ! empty($data['related_document_id'])) {
                $this->service->assign($r->user(), $matter, $data['related_document_id']);
            }
        });

        return response()->noContent();
    }

    public function intelligence(Request $r, string $matter)
    {
        $item = $this->access->matter($r->user(), $matter);

        return $this->service->intelligence($r->user(), $item, $r->query('kind', 'risks'))->paginate(30);
    }

    public function reportGenerated(Request $r, string $matter)
    {
        $item = $this->access->matter($r->user(), $matter);
        app(AuditLogger::class)->log($r->user(), 'matter.report_generated', $item);

        return response()->json(['message' => 'Report generation recorded.']);
    }

    private function validated(Request $r): array
    {
        return $r->validate(['name' => 'required|string|max:255', 'description' => 'nullable|string|max:10000', 'type' => 'nullable|string|max:80']);
    }
}
