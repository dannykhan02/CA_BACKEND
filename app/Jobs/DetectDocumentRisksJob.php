<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\DocumentRisk;
use App\Services\AnthropicClient;
use App\Services\Pipeline\PipelineStageRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DetectDocumentRisksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 60;

    public function __construct(public string $documentId) {}

    public function handle(AnthropicClient $client, PipelineStageRecorder $recorder): void
    {
        $document = Document::find($this->documentId);

        if (! $document || ! $document->extracted_text) {
            return;
        }

        $stage = $recorder->start($document, 'risks');

        try {
            $result = $client->detectDocumentRisks($document->extracted_text, $document->name, $document);
        } catch (\Throwable $e) {
            $recorder->fail($stage, $e->getMessage());
            $this->fail($e);
            return;
        }

        DB::transaction(function () use ($document, $result) {
            DocumentRisk::where('document_id', $document->id)->delete();

            foreach ($result['risks'] as $risk) {
                DocumentRisk::create([
                    'workspace_id' => $document->workspace_id,
                    'document_id' => $document->id,
                    'risk_type' => $risk['risk_type'] ?? null,
                    'title' => $risk['title'],
                    'description' => $risk['description'],
                    'severity' => $risk['severity'],
                    'confidence' => $risk['confidence'],
                    'evidence' => $risk['evidence'],
                    'status' => 'open',
                    'prompt_version' => (string) $result['prompt_version'],
                    'provider' => 'anthropic',
                    'model' => config('services.anthropic.model'),
                ]);
            }
        });

        $recorder->complete($stage, ['risk_count' => count($result['risks'])]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('DetectDocumentRisksJob failed after retries', [
            'document_id' => $this->documentId,
            'error' => $e->getMessage(),
        ]);
    }
}
