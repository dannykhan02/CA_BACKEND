<?php

namespace App\Services\AI\Incremental;

use App\Jobs\ProcessDocumentVisualJob;
use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class VisualPlanner
{
    public function useful(string $bytes): bool
    {
        if (strlen($bytes) < config('document_intelligence.visual_min_bytes')) {
            return false;
        }
        $dimensions = @getimagesizefromstring($bytes);
        if (! $dimensions || $dimensions[0] * $dimensions[1] > 20000000 || min($dimensions[0], $dimensions[1]) < config('document_intelligence.visual_min_dimension')) {
            return false;
        }
        // Reject nearly uniform assets using a small local thumbnail.
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            return false;
        }
        try {
            $colors = [];
            for ($x = 0; $x < 10; $x++) {
                for ($y = 0; $y < 10; $y++) {
                    $colors[imagecolorat($image, (int) ($dimensions[0] * $x / 10), (int) ($dimensions[1] * $y / 10)) >> 4] = true;
                }
            }

            return count($colors) > 4;
        } finally {
            imagedestroy($image);
        }
    }

    public function pump(Document $document, string $key): void
    {
        DB::transaction(function () use ($document, $key) {
            Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $query = fn () => DocumentChunk::where('document_id', $document->id)->where('pipeline_key', $key)->where('stage', 'visual');
            $slots = max(0, config('document_intelligence.concurrency') - $query()->whereIn('status', ['queued', 'running'])->count());
            foreach ($query()->where('status', 'pending')->limit($slots)->get() as $unit) {
                $unit->update(['status' => 'queued']);
                ProcessDocumentVisualJob::dispatch($unit->id)->onQueue('extraction')->afterCommit();
            }
        });
    }

    public function recover(Document $document): void
    {
        $query = fn () => DocumentChunk::where('document_id', $document->id)->where('workspace_id', $document->workspace_id)
            ->whereIn('stage', ['visual', 'visual_plan']);
        foreach ($query()->where('status', 'running')->where('started_at', '<', now()->subMinutes(7))->get() as $unit) {
            if ($unit->stage === 'visual' && isset($unit->result['path'])) {
                Storage::disk('documents')->delete($unit->result['path']);
            }
            $unit->update(['status' => 'uncertain', 'failure_class' => 'visual_interrupted']);
        }
        $query()->where('status', 'queued')->where('updated_at', '<', now()->subMinutes(10))->update(['status' => 'pending']);
        foreach ($query()->whereIn('status', ['pending', 'queued'])->distinct()->pluck('pipeline_key') as $key) {
            $this->pump($document, $key);
        }
    }
}
