<?php

namespace App\Services\Intelligence\B2;

use App\Exceptions\AiProcessingException;
use App\Exceptions\AnthropicStructuredOutputException;
use App\Exceptions\ProviderBusyException;
use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\DocumentChunk;
use App\Services\AI\Incremental\IncrementalPipeline;
use App\Services\AnthropicClient;
use Illuminate\Support\Facades\Log;

/**
 * One end-to-end B2 attempt: project Stage A, build the bounded context, claim the checkpoint, call
 * the provider once, verify, and store the verdict.
 *
 * Every exit stores a status and, when B2 is not usable, a fallback reason, so the read path can
 * always answer "why is the reader seeing the deterministic Brief". A failure here never touches
 * the document's own status, its B1 Brief or anything Stage A wrote: B2 is strictly additive work
 * that happens after the document is already Ready.
 */
class NarrativeSynthesizer
{
    public function __construct(
        private StageASnapshot $snapshots,
        private NarrativeContextBuilder $contexts,
        private NarrativeVerifier $verifier,
        private NarrativeCheckpoint $checkpoints,
        private AnthropicClient $client,
        private IncrementalPipeline $pipeline,
    ) {}

    public function model(): string
    {
        return $this->contexts->model();
    }

    /**
     * @return array{status:string,fallback_reason:string|null,unit_id:string|null,provider_called:bool}
     */
    public function synthesize(Document $document, ?\DateTimeImmutable $asOf = null): array
    {
        $asOf ??= StageASnapshot::today();
        if (! config('intelligence_v2.enabled') || ! config('intelligence_v2.b2.enabled')) {
            return $this->outcome('disabled', 'disabled');
        }

        $snapshot = $this->snapshots->build($document, $asOf);
        $built = $this->contexts->build($snapshot);
        if ($built['supplied'] === []) {
            return $this->outcome('empty_evidence', 'empty_evidence');
        }
        if (count($built['supplied']) < (int) config('intelligence_v2.b2.min_records')) {
            return $this->outcome('insufficient_evidence', 'insufficient_evidence');
        }

        $model = $this->model();
        if (! in_array($model, config('document_intelligence.structured_models'), true)) {
            // Checked before a unit exists, so a misconfigured model reserves nothing.
            return $this->outcome('unavailable', 'unsupported_structured_model');
        }
        $tokens = $this->contexts->tokens($built['context']);
        $hash = $this->contexts->inputHash($built['context'], $model);
        $claim = $this->checkpoints->claim($document, $hash, $tokens, $model);
        $unit = $claim['unit'];
        if (! $unit) {
            return $this->outcome('unavailable', $claim['reason'] ?? 'unavailable');
        }
        if ($claim['reused']) {
            $stored = is_array($unit->result) ? $unit->result : [];

            return $this->outcome((string) ($stored['status'] ?? 'unavailable'),
                $stored['fallback_reason'] ?? null, $unit->id, false);
        }

        $this->client->setRunContext(['chunk_id' => $unit->id, 'pipeline_version' => $unit->pipeline_version,
            'request_attempt' => $unit->attempts]);
        try {
            $response = $this->client->synthesizeBriefNarrative($document, $built['context'], $model);
            $verdict = $this->verifier->verify($response['decoded'], $built['supplied'],
                $built['records'], $snapshot['key_figure_ids']);
            $status = $verdict['status'] === 'verified' ? 'verified' : 'rejected';
            $result = [
                'status' => $status,
                'fallback_reason' => $status === 'verified' ? null : 'verifier_rejected',
                'claims' => $verdict['claims'],
                'audit' => $this->audit($snapshot, $built, $hash, $model, $response['response_hash'], $verdict),
            ];
            $unit->update(['status' => 'completed', 'result' => $result, 'completed_at' => now(),
                'failure_class' => $status === 'verified' ? null : 'verifier_rejected']);
            $this->log($document, $unit, $status, $verdict['reasons'], $built, $tokens);

            return $this->outcome($status, $result['fallback_reason'], $unit->id, true);
        } catch (ProviderBusyException $e) {
            // Nothing was sent: leave the unit claimable so a later attempt can reuse this identity.
            $unit->update(['status' => 'pending', 'failure_class' => 'provider_busy']);
            $this->log($document, $unit, 'deferred', ['provider_busy'], $built, $tokens);

            return $this->outcome('deferred', 'provider_busy', $unit->id, false);
        } catch (\Throwable $e) {
            $failure = match (true) {
                $e instanceof AiProcessingException => $e->classification,
                $e instanceof AnthropicStructuredOutputException => $e->outputStatus,
                default => 'synthesis_failure',
            };
            // Transient failures stay claimable within the attempt bound; everything else is terminal
            // for this exact input. Either way the reader gets B1.
            $unit->update(['status' => $failure === 'transient' ? 'pending' : 'failed',
                'failure_class' => $failure,
                'completed_at' => $failure === 'transient' ? null : now()]);
            $this->log($document, $unit, 'failed', [$failure], $built, $tokens);

            return $this->outcome('failed', $failure, $unit->id, $this->sent($unit));
        } finally {
            // The audit record is the only honest answer to "was this request sent": recordAiRun()
            // writes a row for every request that left the client, and nothing when admission
            // control, the spend ceiling or a configuration check refused before sending. An
            // optimistic flag would permanently commit the reservation for a call never made.
            $this->pipeline->settleCost($unit, $this->sent($unit));
            $this->client->setRunContext([]);
        }
    }

    private function sent(DocumentChunk $unit): bool
    {
        return DocumentAiRun::where('chunk_id', $unit->id)
            ->where('request_attempt', max(1, (int) $unit->attempts))->exists();
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @param  array<string,mixed>  $built
     * @param  array<string,mixed>  $verdict
     * @return array<string,mixed>
     */
    private function audit(array $snapshot, array $built, string $hash, string $model,
        string $responseHash, array $verdict): array
    {
        $settings = config('intelligence_v2.b2');

        return [
            'contract_version' => (string) $settings['contract_version'],
            'prompt_version' => (string) $settings['prompt_version'],
            'prompt_hash' => NarrativePrompt::hash(),
            'verifier_version' => $verdict['verifier_version'],
            'model' => $model,
            'evidence_input_hash' => $hash,
            'response_hash' => $responseHash,
            'verifier_status' => $verdict['status'],
            'verifier_reasons' => $verdict['reasons'],
            'claim_count' => count($verdict['claims']),
            'rejected_claim_count' => count($verdict['rejected']),
            'supplied_evidence' => count($built['supplied']),
            'omitted_evidence' => $built['omitted'],
            'pipeline_key' => $snapshot['pipeline_key'],
            'extraction_version' => $snapshot['extraction_version'],
            'as_of' => $snapshot['as_of'],
            'generated_at' => now()->toAtomString(),
        ];
    }

    /** @return array{status:string,fallback_reason:string|null,unit_id:string|null,provider_called:bool} */
    private function outcome(string $status, ?string $reason, ?string $unitId = null,
        bool $providerCalled = false): array
    {
        return ['status' => $status, 'fallback_reason' => $reason, 'unit_id' => $unitId,
            'provider_called' => $providerCalled];
    }

    /**
     * Metadata only: ids, hashes, counts, statuses and reasons. Never narrative text, evidence text
     * or any part of the document.
     *
     * @param  list<string>  $reasons
     * @param  array<string,mixed>  $built
     */
    private function log(Document $document, DocumentChunk $unit, string $status, array $reasons,
        array $built, int $tokens): void
    {
        Log::info('docintel.v2.b2_attempt', [
            'document_id' => $document->id, 'chunk_id' => $unit->id, 'status' => $status,
            'fallback_reason' => $status === 'verified' ? null : ($reasons[0] ?? null),
            'verifier_reasons' => $reasons, 'attempt' => $unit->attempts,
            'model' => $this->model(), 'contract_version' => config('intelligence_v2.b2.contract_version'),
            'prompt_version' => config('intelligence_v2.b2.prompt_version'),
            'input_hash' => $unit->input_hash, 'context_tokens_estimated' => $tokens,
            'supplied_evidence' => count($built['supplied']), 'omitted_evidence' => $built['omitted'],
            'eligible_evidence' => $built['eligible'],
            'estimated_cost_usd' => $unit->cost_accounting['estimate'] ?? null,
        ]);
    }
}
