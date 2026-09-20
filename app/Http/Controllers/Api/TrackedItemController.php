<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TrackedItem;
use App\Services\IntelligenceAccess;
use App\Services\TrackingService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class TrackedItemController extends Controller
{
    public function __construct(private IntelligenceAccess $access, private TrackingService $tracking) {}

    public function index(Request $r)
    {
        $r->validate(['filter' => 'nullable|in:open,upcoming,overdue,completed,dismissed']);
        $q = TrackedItem::whereIn('document_id', $this->access->documents($r->user())->select('id'))
            ->where('workspace_id', $r->user()->current_workspace_id)->with('document:id,name');
        $filter = $r->query('filter', 'open');
        $q->where('status', in_array($filter, ['completed', 'dismissed']) ? $filter : 'open');
        if ($filter === 'upcoming') {
            $q->whereDate('due_date', '>=', today());
        }
        if ($filter === 'overdue') {
            $q->whereDate('due_date', '<', today());
        }
        if ($r->filled('document_id')) {
            $doc = $this->access->document($r->user(), $r->query('document_id'));
            $q->where('document_id', $doc->id);
        }

        return $q->orderBy('due_date')->orderBy('id')->paginate(30);
    }

    public function store(Request $r)
    {
        $data = $r->validate(['document_id' => 'required|uuid', 'deadline_id' => 'required|integer|min:1']);

        return response()->json(['data' => $this->tracking->track($r->user(), $data['document_id'], $data['deadline_id'])], 201);
    }

    public function update(Request $r, string $trackedItem)
    {
        $item = TrackedItem::where('workspace_id', $this->access->workspace($r->user(), true))->findOrFail($trackedItem);
        $this->access->document($r->user(), $item->document_id, true);
        $data = $r->validate(['title' => 'sometimes|required|string|max:255', 'due_date' => 'nullable|date_format:Y-m-d',
            'status' => 'sometimes|required|in:open,completed,dismissed', 'notes' => 'nullable|string|max:10000',
            'remind_at' => 'nullable|date|after:now']);
        // Reminders are explicitly owned by the requesting user.
        if (array_key_exists('remind_at', $data)) {
            abort_unless($item->created_by === $r->user()->id, 403, 'Only the tracker can configure their reminder.');
            if ($data['remind_at']) {
                $data['remind_at'] = Carbon::parse($data['remind_at'])->utc();
            }
            $data['reminded_at'] = null;
        }
        if (isset($data['status'])) {
            $data['completed_at'] = $data['status'] === 'completed' ? ($item->completed_at ?? now()) : null;
        }
        $item->update($data);

        return response()->json(['data' => $item->load('document:id,name')]);
    }
}
