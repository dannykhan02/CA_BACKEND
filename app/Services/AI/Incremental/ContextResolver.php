<?php

namespace App\Services\AI\Incremental;

use App\Exceptions\ProviderBusyException;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\DocumentEvidence;
use App\Services\AI\AiModels;
use App\Services\AI\AiPricing;
use App\Services\AnthropicClient;
use Illuminate\Support\Facades\DB;

class ContextResolver
{
    /** Retrieval only proposes targets; similar wording never establishes identity. */
    public function candidates(DocumentEvidence $reference, array $evidence): array
    {
        $words = array_filter(preg_split('/\W+/u', mb_strtolower($reference->data['quote'])),
            fn ($word) => mb_strlen($word) > 4 && ! in_array($word, ['these', 'those', 'their', 'which', 'aforementioned']));
        $origin = $reference->sources[0]['start_offset'] ?? 0;
        $candidates = [];
        foreach ($evidence as $candidate) {
            if ($candidate->kind === 'unresolved' || $candidate->id === $reference->id) {
                continue;
            }
            $near = abs(($candidate->sources[0]['start_offset'] ?? 0) - $origin);
            $score = count(array_filter($words, fn ($w) => str_contains(mb_strtolower($candidate->data['label'].' '.$candidate->data['value']), $w)));
            if ($score > 0 || $near < 5000) {
                $candidates[] = ['evidence' => $candidate, 'score' => $score * 100000 - $near];
            }
        }
        usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_map(fn ($item) => ['id' => $item['evidence']->id, 'label' => $item['evidence']->data['label'],
            'quote' => mb_substr($item['evidence']->data['quote'], 0, 600)], array_slice($candidates, 0, 3));
    }

    public function resolve(Document $document): void
    {
        $all = DocumentEvidence::where('workspace_id', $document->workspace_id)->where('document_id', $document->id)
            ->where('pipeline_key', $document->ai_pipeline['key'])->orderBy('identity')->get();
        $requests = [];
        foreach ($all->where('kind', 'unresolved')->take(8) as $reference) {
            $candidates = $this->candidates($reference, $all->all());
            if ($candidates) {
                $requests[] = ['id' => $reference->id, 'quote' => mb_substr($reference->data['quote'], 0, 600), 'candidates' => $candidates];
            }
        }
        if (! $requests) {
            return;
        }
        $hash = hash('sha256', json_encode([$requests, app(AiModels::class)->forTask('context_resolution'), config('document_intelligence.prompt_version')]));
        $unit = DocumentChunk::firstOrCreate(['document_id' => $document->id, 'pipeline_key' => $document->ai_pipeline['key'],
            'identity' => 'context:'.$hash], ['workspace_id' => $document->workspace_id, 'stage' => 'context',
                'input_hash' => $hash, 'pipeline_version' => config('document_intelligence.pipeline_version'), 'prompt_version' => '1']);
        if ($unit->status !== 'completed') {
            $claimed = DB::transaction(function () use ($unit, $document, $requests) {
                $locked = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
                $unit->refresh();
                if ($unit->status !== 'pending') {
                    return false;
                }
                $cost = app(AiPricing::class)->reserve(app(AiModels::class)->forTask('context_resolution'),
                    strlen(json_encode($requests)) + 2000, (int) config('document_intelligence.context_max_tokens'));
                $pipeline = app(IncrementalPipeline::class);
                if (! $pipeline->canReserve($locked, $cost)) {
                    DocumentChunk::whereKey($unit->id)->where('status', 'pending')->update(['status' => 'budget', 'failure_class' => 'budget_exceeded']);

                    return false;
                }

                $pipeline->reserveCost($unit, $cost);

                return true;
            });
            if (! $claimed) {
                return;
            }
            try {
                $client = app(AnthropicClient::class);
                $client->setRunContext(['chunk_id' => $unit->id, 'pipeline_version' => $unit->pipeline_version, 'request_attempt' => 1]);
                $result = $client->resolveEvidenceReferences($document, $requests);
                $unit->update(['result' => $result, 'status' => 'completed', 'completed_at' => now()]);
            } catch (ProviderBusyException) {
                // Optional and nothing was sent: settle at zero; references stay disclosed as unresolved.
                app(IncrementalPipeline::class)->settleCost($unit, false);
                $unit->update(['status' => 'failed', 'failure_class' => 'provider_busy']);

                return;
            } catch (\Throwable) {
                $unit->update(['status' => 'failed', 'failure_class' => 'optional_resolution']);

                return;
            } finally {
                app(IncrementalPipeline::class)->settleCost($unit);
            }
        }
        foreach ($unit->result['resolutions'] ?? [] as $resolution) {
            $request = collect($requests)->firstWhere('id', $resolution['reference_id'] ?? null);
            if (! $request || ($resolution['confidence'] ?? 0) < 0.98
                || ! in_array($resolution['target_id'] ?? null, array_column($request['candidates'], 'id'), true)) {
                continue;
            }
            $reference = $all->firstWhere('id', $resolution['reference_id']);
            $reference->update(['data' => [...$reference->data, 'resolved_evidence_id' => $resolution['target_id'], 'resolution_status' => 'inferred']]);
        }
    }
}
