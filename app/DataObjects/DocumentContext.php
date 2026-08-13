<?php

namespace App\DataObjects;

/**
 * Day 9 Batch 3 — carries everything DocumentContextRetriever found for a
 * Q&A query: matched documents' metadata, their extracted entities/risks/
 * deadlines, and the raw chunk text that matched the query embedding.
 * toPromptContext() is what actually gets injected into the document_qa
 * prompt via PromptManager — same {{document_text}} placeholder convention
 * every other prompt already uses, so this class owns formatting, not the
 * prompt template itself.
 */
readonly class DocumentContext
{
    public function __construct(
        public array $documents,       // [['id','name','document_type','classification'], ...]
        public array $entities,        // [['document_id','type','value'], ...]
        public array $risks,           // [['document_id','title','severity','description'], ...]
        public array $deadlines,       // [['document_id','title','date_type','due_date','relative_text'], ...]
        public array $relevantChunks,  // [['document_id','text','score'], ...]
    ) {}

    public function documentIds(): array
    {
        return array_values(array_unique(array_column($this->documents, 'id')));
    }

    public function isEmpty(): bool
    {
        return empty($this->documents);
    }

    /**
     * Bounded-length text block for the prompt. Same truncation discipline
     * as every other AnthropicClient prompt builder (Day 8 Batch 4) — this
     * gets truncated again defensively inside buildDocumentQaPrompt(), but
     * capping the chunk text here too avoids assembling something wildly
     * oversized in memory when topK is large.
     */
    public function toPromptContext(): string
    {
        return json_encode([
            'documents' => $this->documents,
            'entities' => $this->entities,
            'risks' => $this->risks,
            'deadlines' => $this->deadlines,
            'relevant_excerpts' => $this->relevantChunks,
        ], JSON_PRETTY_PRINT);
    }
}
