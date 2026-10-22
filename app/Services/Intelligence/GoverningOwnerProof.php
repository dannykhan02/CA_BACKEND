<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use App\Services\AI\Incremental\EvidenceSpanSet;

/** Exact source relationship between a metric cell and its governing organization. */
class GoverningOwnerProof
{
    public function companyClaim(array $identity, ?string $unit): bool
    {
        $scope = trim((string) ($identity['scope'] ?? ''));
        $concept = (string) ($identity['concept'] ?? '');

        return $this->hasCompanyName($scope)
            || preg_match('/\b(?:revenue|turnover|operating costs?|operating profit|operating margin|net income|gross profit|earnings|cash flow|assets|liabilities|funding|budget|investment)\b/iu', $concept) === 1;
    }

    /** @return array{entity:string,kind:string,relationship:string,source_revision:string,source_spans:list<array>}|null */
    public function resolve(Document $document, EvidenceSpanSet $set, array $value,
        ?array $tableProof, array $context, string $claimedScope): ?array
    {
        $sourceRevision = hash('sha256', (string) $document->extracted_text);
        if (($document->ai_pipeline['text_hash'] ?? $sourceRevision) !== $sourceRevision) {
            return null;
        }
        $claim = trim($claimedScope);
        $anchor = isset($tableProof['header_span_id'])
            ? $set->resolve($tableProof['header_span_id']) : null;
        $caption = $anchor === null ? null : $this->preceding($set, $anchor);
        $local = $caption !== null && $caption['page'] === $value['page']
            && $caption['end_offset'] <= $anchor['start_offset'] ? $caption : null;
        $cited = is_string($context['subject_span_id'] ?? null)
            ? $set->resolve($context['subject_span_id']) : null;
        if ($cited !== null && ! $this->current($document, $cited)) {
            return null;
        }

        // A specific table caption outranks a page banner. A conflicting or multi-entity
        // caption is not repaired by a model citation to some other part of the page.
        if ($local !== null && $this->ownerCaption($local['text'])) {
            $names = $this->companyNames($local['text']);
            if (count($names) !== 1 || ($claim !== '' && ! $this->containsName($local['text'], $claim))) {
                return null;
            }
            if ($cited !== null && $cited['span_id'] !== $local['span_id']
                && $cited['span_id'] !== $value['span_id']) {
                foreach ($this->companyNames($cited['text']) as $citedName) {
                    if (! $this->sameName($citedName, $names[0])) {
                        return null;
                    }
                }
            }
            if ($claim !== '' && $cited !== null && $cited['span_id'] !== $local['span_id']
                && $cited['span_id'] !== $value['span_id']
                && ! $this->containsName($cited['text'], $claim)) {
                return null;
            }

            return $this->proof($claim !== '' ? $claim : $names[0], 'table_caption',
                [$local, $anchor, $value], $sourceRevision, $set->version);
        }

        // A company printed in the same row as the value is an exact local owner.
        $rowNames = $this->companyNames($value['text']);
        if (count($rowNames) === 1
            && ($claim === '' || $this->containsName($value['text'], $claim))
            && ($cited === null || $cited['span_id'] === $value['span_id'])) {
            return $this->proof($claim !== '' ? $claim : $rowNames[0], 'value_row',
                [$value], $sourceRevision, $set->version);
        }

        // An explicit company claim can use a cited page/section heading only when the
        // table has no competing caption and no other company occurs on the path to it.
        // A blank scope never acquires a company merely from a document heading.
        if ($claim === '' || $cited === null || ! $this->containsName($cited['text'], $claim)
            || $cited['page'] !== $value['page'] || $cited['start_offset'] >= $value['start_offset']
            || $anchor === null || $anchor['page'] !== $value['page']
            || ! in_array($cited['type'], ['heading', 'section', 'table_row'], true)) {
            return null;
        }
        $headingNames = $this->companyNames($cited['text']);
        if (count($headingNames) !== 1 || ! $this->sameName($headingNames[0], $claim)) {
            return null;
        }
        $between = 0;
        foreach ($set->all() as $span) {
            if ($span['page'] !== $value['page']
                || $span['start_offset'] <= $cited['start_offset']
                || $span['start_offset'] >= $anchor['start_offset']) {
                continue;
            }
            if (++$between > 2 || ($span['type'] === 'table_row'
                && $span['key'] !== ($local['span_id'] ?? null))) {
                return null;
            }
        }
        foreach ($set->all() as $span) {
            if ($span['page'] !== $value['page'] || $span['start_offset'] < $cited['start_offset']
                || $span['start_offset'] >= $value['start_offset']) {
                continue;
            }
            $source = $set->resolve($span['key']);
            foreach ($this->companyNames($source['text']) as $name) {
                if (! $this->sameName($name, $claim)) {
                    return null;
                }
            }
        }

        return $this->proof($claim, 'cited_section_to_table', [$cited, $anchor, $value],
            $sourceRevision, $set->version);
    }

    private function preceding(EvidenceSpanSet $set, array $anchor): ?array
    {
        $previous = null;
        foreach ($set->all() as $span) {
            if ($span['key'] === $anchor['span_id']) {
                return $previous === null ? null : $set->resolve($previous['key']);
            }
            $previous = $span;
        }

        return null;
    }

    private function ownerCaption(string $text): bool
    {
        return $this->hasCompanyName($text)
            || preg_match('/\b(?:subsidiary|business unit|division|reporting entity)\b/iu', $text) === 1;
    }

    private function hasCompanyName(string $text): bool
    {
        return $this->companyNames($text) !== [];
    }

    /** @return list<string> */
    private function companyNames(string $text): array
    {
        preg_match_all('/\b\p{Lu}[\p{L}\p{N}&.-]*(?:\s+\p{Lu}[\p{L}\p{N}&.-]*){0,5}\s+(?i:Ltd|Limited|PLC|LLC|Inc|Corporation)\b/u',
            $text, $matches);

        return array_values(array_unique(array_map('trim', $matches[0])));
    }

    private function containsName(string $text, string $name): bool
    {
        return preg_match('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/iu', $text) === 1;
    }

    private function sameName(string $left, string $right): bool
    {
        return mb_strtolower(trim($left)) === mb_strtolower(trim($right));
    }

    private function current(Document $document, array $source): bool
    {
        return mb_substr((string) $document->extracted_text, $source['start_offset'],
            $source['end_offset'] - $source['start_offset']) === $source['text'];
    }

    private function proof(string $entity, string $relationship, array $spans,
        string $revision, string $extractionVersion): array
    {
        return ['entity' => $entity, 'kind' => 'organization', 'relationship' => $relationship,
            'source_revision' => $revision, 'extraction_version' => $extractionVersion,
            'source_spans' => array_map(static fn ($span) => [
                'span_id' => $span['span_id'], 'page' => $span['page'],
                'start_offset' => $span['start_offset'], 'end_offset' => $span['end_offset'],
            ], $spans)];
    }
}
