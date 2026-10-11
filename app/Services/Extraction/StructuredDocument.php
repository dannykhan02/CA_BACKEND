<?php

namespace App\Services\Extraction;

/** One parser revision. Offsets are Unicode character offsets into the exact compatibility text. */
final class StructuredDocument
{
    public const POLICY = 'structured-extraction.v1';

    public function __construct(
        public readonly string $format,
        public readonly string $parserVersion,
        public readonly string $text,
        public readonly array $nodes,
        public readonly array $capabilities,
    ) {
        if (count($nodes) > 100000) {
            throw new \RuntimeException('Structured node limit exceeded.');
        }
        $ids = [];
        foreach ($nodes as $node) {
            $id = $node['id'] ?? null;
            if (! is_string($id) || $id === '' || isset($ids[$id])) {
                throw new \InvalidArgumentException('Invalid structured source occurrence.');
            }
            $ids[$id] = true;
            $start = $node['start'] ?? null;
            $end = $node['end'] ?? null;
            if (($start === null) !== ($end === null)) {
                throw new \InvalidArgumentException('Incomplete text projection.');
            }
            if ($start !== null && (! is_int($start) || ! is_int($end) || $start < 0 || $end <= $start
                || mb_substr($text, $start, $end - $start) !== ($node['text'] ?? null))) {
                throw new \InvalidArgumentException('Structured text projection differs from extracted text.');
            }
        }
        foreach ($nodes as $node) {
            if (isset($node['parent']) && ! isset($ids[$node['parent']])) {
                throw new \InvalidArgumentException('Structured parent is missing.');
            }
        }
    }

    public function nodesAt(int $start, int $end): array
    {
        return array_values(array_filter($this->nodes, static fn (array $node): bool => isset($node['start'], $node['end']) && $node['start'] <= $start && $node['end'] >= $end));
    }

    public function node(string $id): ?array
    {
        foreach ($this->nodes as $node) {
            if ($node['id'] === $id) {
                return $node;
            }
        }

        return null;
    }
}
