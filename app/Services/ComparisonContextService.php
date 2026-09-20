<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentEmbedding;
use App\Services\Embeddings\TextChunker;

class ComparisonContextService
{
    /** Reuse indexed text; no embedding/provider call. Bound both candidate and submitted text. */
    public function excerpts(Document $document): array
    {
        $stored = DocumentEmbedding::where('document_id', $document->id)
            ->where('workspace_id', $document->workspace_id)->orderBy('chunk_index')->limit(200)
            ->get(['chunk_index', 'chunk_text']);
        $chunks = $stored->isEmpty()
            ? app(TextChunker::class)->chunk(mb_substr($document->extracted_text ?? '', 0, 240000))
            : $stored->pluck('chunk_text', 'chunk_index')->all();
        $candidates = [];
        foreach ($chunks as $index => $text) {
            $text = mb_substr($text, 0, 1600);
            preg_match_all('/\b(payment|notice|termination|liability|renewal|deadline|shall|must|requirement|budget|KES|KSh|USD|amend|clause|obligation|penalty)\b/iu', $text, $matches);
            $candidates[] = ['chunk_id' => 'chunk-'.$index, 'text' => $text, 'score' => count($matches[0]), 'index' => (int) $index];
        }
        usort($candidates, fn ($a, $b) => ($b['score'] <=> $a['score']) ?: ($a['index'] <=> $b['index']));

        return array_map(fn ($c) => ['chunk_id' => $c['chunk_id'], 'text' => $c['text']], array_slice($candidates, 0, 12));
    }
}
