<?php

namespace App\Services\Intelligence;

use App\Services\AI\Incremental\EvidenceMerger;

/** Derives two narrow, ephemeral mention roles from one already-grounded canonical record. */
class RecordOwnedMentionProjector
{
    public function __construct(private EvidenceMerger $normalizer) {}

    /**
     * @param array<string,mixed> $record
     * @return list<array{surface:string,canonical:string,role:string,basis_field:string,source_quote_match:string,record_id:string}>
     */
    public function project(array $record): array
    {
        if (($record['provenance']['origin'] ?? null) !== 'document') {
            return [];
        }
        $data = $record['data'] ?? [];
        $label = trim((string) ($data['label'] ?? ''));
        $value = trim((string) ($data['value'] ?? ''));
        $sourceId = $record['source_id'] ?? $record['identity'] ?? null;
        if (! is_string($sourceId) || $sourceId === '' || $label === '' || $value === '') {
            return [];
        }
        $quotes = array_values(array_filter(array_column($record['sources'] ?? [], 'quote'), 'is_string'));
        if ($quotes === [] && is_string($data['quote'] ?? null)) {
            $quotes[] = $data['quote'];
        }
        if ($quotes === []) {
            return [];
        }

        if (($record['kind'] ?? null) === 'metric') {
            // A named funding category immediately followed by the measured observation "income".
            // A typed monetary observation and same-record quoted category are both required.
            if (! preg_match('/^([\p{Lu}][\p{L}\p{M}]+(?:\s+[\p{Lu}][\p{L}\p{M}]+){1,3})\s+income(?:\s+from\s+.+)?$/u',
                $label, $match) || ($record['typed']['value']['type'] ?? null) !== 'money'
                || ! is_numeric($record['typed']['value']['number'] ?? null)
                || trim((string) ($data['subject'] ?? '')) === '') {
                return [];
            }
            $concept = $match[1];
            foreach ($quotes as $quote) {
                if ($this->literal($quote, $concept)
                    && preg_match('/\b'.preg_quote($concept, '/').'\s+income\b/iu',
                        $this->spaces($quote)) === 1) {
                    return [$this->mention($concept, $concept, 'metric_concept', 'label', $quote, $sourceId)];
                }
            }

            return [];
        }

        if (($record['kind'] ?? null) !== 'fact'
            || ! preg_match('/^([\p{Lu}][\p{L}\p{M}]+(?:\s+[\p{Lu}][\p{L}\p{M}]+){1,3})\s+Mission\s+launch$/u',
                $label, $labelMatch)) {
            return [];
        }
        $name = $labelMatch[1];
        $namePattern = preg_quote($name, '/');
        // The action and both names must be a single construction in the fact value and quote.
        $pattern = '/\b(?<actor>.+?)\s+launched\s+the\s+'. $namePattern
            .'(?:\s*\((?<alias>[\p{Lu}][\p{L}\p{M}]+(?:\s+[\p{Lu}][\p{L}\p{M}]+){1,3})\))?\s+Mission\b/iu';
        if (preg_match($pattern, $this->spaces($value), $valueMatch) !== 1) {
            return [];
        }
        $alias = $valueMatch['alias'] ?? '';
        foreach ($quotes as $quote) {
            $normalizedQuote = $this->spaces($quote);
            if (! $this->literal($normalizedQuote, $name)
                || preg_match($pattern, $normalizedQuote, $quoteMatch) !== 1
                || $this->normalizer->normalize((string) ($quoteMatch['alias'] ?? ''))
                    !== $this->normalizer->normalize($alias)) {
                continue;
            }
            $actor = trim((string) ($valueMatch['actor'] ?? ''));
            // The quoted actor may have an introductory year or determiner; the value's actor
            // must occur immediately before the action in that same quoted construction.
            if ($actor === '' || ! preg_match('/(?:^|\s)'.preg_quote($actor, '/').'\s+launched\s+the\s+/iu',
                $normalizedQuote)) {
                continue;
            }
            $mentions = [$this->mention($name, $name.' Mission', 'program_or_initiative',
                'value', $quote, $sourceId),
                $this->mention($name.' Mission', $name.' Mission', 'program_or_initiative',
                    'label', $quote, $sourceId)];
            if ($alias !== '' && $this->literal($normalizedQuote, $alias)) {
                $mentions[] = $this->mention($alias, $name.' Mission', 'program_or_initiative',
                    'value', $quote, $sourceId);
                $mentions[] = $this->mention($alias.' Mission', $name.' Mission',
                    'program_or_initiative', 'value', $quote, $sourceId);
            }

            return $mentions;
        }

        return [];
    }

    public function literal(string $text, string $surface): bool
    {
        return preg_match('/(?<![\p{L}\p{M}])'.preg_quote($this->spaces($surface), '/').'(?![\p{L}\p{M}])/iu',
            $this->spaces($text)) === 1;
    }

    private function spaces(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /** @return array{surface:string,canonical:string,role:string,basis_field:string,source_quote_match:string,record_id:string} */
    private function mention(string $surface, string $canonical, string $role, string $field,
        string $quote, string $sourceId): array
    {
        return ['surface' => $surface, 'canonical' => $canonical, 'role' => $role,
            'basis_field' => $field, 'source_quote_match' => $quote, 'record_id' => $sourceId];
    }
}
