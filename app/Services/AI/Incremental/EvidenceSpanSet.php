<?php

namespace App\Services\AI\Incremental;

/**
 * An immutable, ordered set of evidence spans for one extraction version.
 *
 * The set holds offsets, never a second copy of the document text: resolve() always returns
 * the exact characters currently stored in extracted_text, so an evidence span cannot drift
 * away from its source. A chunk-level set keeps a reference to the document-level set, which
 * is what separates "this ID does not exist" from "this ID is not in your chunk".
 */
class EvidenceSpanSet
{
    /** @var array<string,array{ordinal:int,key:string,page:int|null,start_offset:int,end_offset:int,type:string}> */
    private array $byKey = [];

    /**
     * @param  list<array{ordinal:int,key:string,page:int|null,start_offset:int,end_offset:int,type:string}>  $spans
     */
    public function __construct(
        public readonly string $version,
        private readonly string $text,
        private readonly array $spans,
        private readonly ?self $document = null,
    ) {
        foreach ($spans as $span) {
            $this->byKey[$span['key']] = $span;
        }
    }

    public function isEmpty(): bool
    {
        return $this->spans === [];
    }

    public function count(): int
    {
        return count($this->spans);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->byKey);
    }

    public function has(string $key): bool
    {
        return isset($this->byKey[$key]);
    }

    /** False only for an ID that exists nowhere in this extraction version. */
    public function knownToDocument(string $key): bool
    {
        return ($this->document ?? $this)->has($key);
    }

    public function ordinal(string $key): ?int
    {
        return ($this->document ?? $this)->byKey[$key]['ordinal'] ?? null;
    }

    /**
     * The exact original extracted text for one span, with its location. DocIntel retrieves this;
     * the model never supplies it.
     *
     * @return array{span_id:string,page:int|null,start_offset:int,end_offset:int,text:string,type:string}|null
     */
    public function resolve(string $key): ?array
    {
        $span = $this->byKey[$key] ?? ($this->document ?? $this)->byKey[$key] ?? null;
        if ($span === null) {
            return null;
        }

        return ['span_id' => $span['key'], 'page' => $span['page'], 'start_offset' => $span['start_offset'],
            'end_offset' => $span['end_offset'], 'type' => $span['type'],
            'text' => mb_substr($this->text, $span['start_offset'], $span['end_offset'] - $span['start_offset'])];
    }

    /** Spans fully inside a chunk's half-open offset range; chunk boundaries are span boundaries. */
    public function forRange(int $start, int $end): self
    {
        $spans = array_values(array_filter($this->spans,
            fn ($span) => $span['start_offset'] >= $start && $span['end_offset'] <= $end));

        return new self($this->version, $this->text, $spans, $this->document ?? $this);
    }

    /**
     * The labeled source text sent to the model. Each span is introduced by its own ID, so the
     * model can point at evidence instead of reproducing it.
     */
    public function render(): string
    {
        $out = [];
        foreach ($this->spans as $span) {
            $out[] = '['.$span['key'].']'."\n".mb_substr($this->text, $span['start_offset'],
                $span['end_offset'] - $span['start_offset']);
        }

        return implode("\n\n", $out);
    }

    /** @return list<array{ordinal:int,key:string,page:int|null,start_offset:int,end_offset:int,type:string}> */
    public function all(): array
    {
        return $this->spans;
    }
}
