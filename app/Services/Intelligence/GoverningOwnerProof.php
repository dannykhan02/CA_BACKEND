<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use App\Services\AI\Incremental\EvidenceGrounding;
use App\Services\AI\Incremental\EvidenceSpanSet;

/** Exact source relationship between a metric cell and its governing organization. */
class GoverningOwnerProof
{
    /** A parser-owned table-to-heading edge supplies the governing organization. */
    public function resolveStructured(Document $document, array $path, array $value,
        string $claimedScope): ?array
    {
        $entities = $path['entities'] ?? [];
        $row = $path['row'] ?? null;
        $table = $path['table'] ?? null;
        if (count($entities) !== 1 || ! is_array($row) || ! is_array($table)
            || $entities[0]['page'] !== $value['page']
            || $row['page'] !== $value['page'] || $table['page'] !== $value['page']
            || $entities[0]['end_offset'] >= $value['start_offset']
            || $row['end_offset'] >= $value['start_offset']
            || $table['kind'] !== 'table') {
            return null;
        }
        $names = $this->companyNames($entities[0]['text']);
        if (count($names) !== 1 || (trim($claimedScope) !== ''
            && ! $this->sameName($names[0], trim($claimedScope)))) {
            return null;
        }
        if (preg_match('/^(.*?)\b(?:revenues?|expenses?|income|profit|loss|cash flow|assets?|liabilities|earnings|dividends?)\b/iu',
            $row['text'], $metricMatch) !== 1) {
            return null;
        }
        $prefix = trim($metricMatch[1]);
        if ($prefix !== '' && preg_match('/^(?:(?:total|net|gross|operating|adjusted|non-gaap|gaap|consolidated|basic|diluted|attributable|underlying|recurring)\s*)+$/iuD',
            $prefix) !== 1) {
            return null;
        }
        // A row that names a different scoped business unit cannot inherit the
        // report-wide organization merely because it sits in a consolidated table.
        if (preg_match('/\b(?:segment|division|business unit)\b/iu', $row['text']) === 1
            && ! $this->containsName($row['text'], $names[0])) {
            return null;
        }
        $revision = hash('sha256', (string) $document->extracted_text);
        $version = app(EvidenceGrounding::class)->version($document);

        return ['entity' => $names[0], 'kind' => 'organization',
            'relationship' => 'structured_table_heading', 'source_revision' => $revision,
            'extraction_version' => $version, 'source_provenance_set_id' => $path['set_id'],
            'source_anchor_ids' => [$entities[0]['anchor_id'], $table['anchor_id'],
                $row['anchor_id'], $value['anchor_id']],
            'source_spans' => array_map(static fn ($anchor) => [
                'span_id' => $anchor['anchor_id'], 'page' => $anchor['page'],
                'start_offset' => $anchor['start_offset'], 'end_offset' => $anchor['end_offset'],
            ], [$entities[0], $row, $value])];
    }

    public function companyClaim(array $identity, ?string $unit): bool
    {
        $scope = trim((string) ($identity['scope'] ?? ''));
        // Incremental findings may carry the financial name as `label` before
        // concept normalization. The ownership gate must see that claim too.
        $concept = trim((string) ($identity['concept'] ?? '').' '.(string) ($identity['label'] ?? ''));

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
        if ($local !== null && ! preg_match('/\bPage\s+\d+\s*$/iu', $local['text'])
            && $this->ownerCaption($local['text'])) {
            $names = $this->companyNames($local['text']);
            if (count($names) !== 1 || ! $this->namedTableCaption($local['text'], $names[0])
                || ($claim !== '' && ! $this->containsName($local['text'], $claim))) {
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

        // A report-wide financial table can inherit its reporting entity only through
        // the cover's named report identity, the exact repeated report header on this
        // page, and the immediately adjacent financial section/table caption. The
        // page header by itself says which document this is, not who owns every table.
        if ($claim === '' && $anchor !== null && $local !== null) {
            $reporting = $this->reportingEntityTable($set, $anchor, $local, $value, $cited);
            if ($reporting !== null) {
                return $this->proof($reporting['entity'], 'reporting_entity_section_table',
                    $reporting['spans'], $sourceRevision, $set->version);
            }
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

    /** @return array{entity:string,spans:list<array>}|null */
    private function reportingEntityTable(EvidenceSpanSet $set, array $anchor,
        array $caption, array $value, ?array $cited): ?array
    {
        if ($anchor['page'] === null || $caption['page'] !== $anchor['page']
            || $value['page'] !== $anchor['page']
            || ! in_array($caption['type'], ['section', 'list_item', 'sentence'], true)
            || ! preg_match('/\b(?:financial|income statement|profit and loss|balance sheet|cash flow)\b/iu',
                $caption['text'])) {
            return null;
        }

        $first = null;
        $cover = null;
        $pageBanner = null;
        foreach ($set->all() as $span) {
            $source = $set->resolve($span['key']);
            if ($source === null) {
                return null;
            }
            if ($source['page'] === 1) {
                $first ??= $source;
                if ($cover === null && in_array($source['type'], ['heading', 'section'], true)
                    && count($this->companyNames($source['text'])) === 1
                    && preg_match('/\b(?:annual|quarterly|board|financial|management)\b.{0,80}\b(?:report|review|pack|statements?)\b/isu',
                        $source['text'])) {
                    $cover = $source;
                }
            }
            if ($source['page'] === $anchor['page'] && $pageBanner === null) {
                $pageBanner = $source;
            }
        }
        if ($first === null || $cover === null || $pageBanner === null
            || $first['start_offset'] >= $cover['start_offset']
            || $pageBanner['start_offset'] >= $caption['start_offset']
            || $this->bannerIdentity($first['text']) !== $this->bannerIdentity($pageBanner['text'])) {
            return null;
        }
        $names = $this->companyNames($cover['text']);
        $entity = $names[0];
        if (count($this->companyNames($first['text'])) !== 1
            || ! $this->sameName($this->companyNames($first['text'])[0], $entity)
            || ($cited !== null && $cited['span_id'] !== $value['span_id']
                && $cited['span_id'] !== $caption['span_id']
                && $cited['span_id'] !== $pageBanner['span_id']
                && $cited['span_id'] !== $cover['span_id'])) {
            return null;
        }

        // Any distinct named organization in the cover or on the path from this
        // page's banner to the value makes report-wide ownership ambiguous.
        foreach ($set->all() as $span) {
            if (! (($span['page'] === 1 && $span['start_offset'] <= $cover['end_offset'])
                || ($span['page'] === $value['page']
                    && $span['start_offset'] >= $pageBanner['start_offset'])
                || (preg_match('/\bcontinued\b/iu', $caption['text'])
                    && $span['page'] === $value['page'] - 1))) {
                continue;
            }
            foreach ($this->companyNames($set->resolve($span['key'])['text']) as $name) {
                if (! $this->sameName($name, $entity)) {
                    return null;
                }
            }
        }

        return ['entity' => $entity,
            'spans' => [$first, $cover, $pageBanner, $caption, $anchor, $value]];
    }

    private function bannerIdentity(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+Page\s+\d+\s*$/iu', '', $text)));
    }

    private function ownerCaption(string $text): bool
    {
        if (preg_match('/\b(?:subsidiary|business unit|division|reporting entity)\b/iu', $text)) {
            return true;
        }
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if ($this->hasCompanyName($line)
                && preg_match('/\b(?:financial|revenue|costs?|profit|income|cash flow|assets|liabilities)\b/iu',
                    $line)) {
                return true;
            }
        }

        return false;
    }

    private function namedTableCaption(string $text, string $name): bool
    {
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (preg_match('/^\s*'.preg_quote($name, '/').'.{0,80}\b(?:results|performance|statement|table|summary|by\s+(?:quarter|year|region|segment|function))\b/iu',
                $line)) {
                return true;
            }
        }

        return false;
    }

    private function hasCompanyName(string $text): bool
    {
        return $this->companyNames($text) !== [];
    }

    /** @return list<string> */
    private function companyNames(string $text): array
    {
        preg_match_all('/\b\p{Lu}[\p{L}\p{N}&.,-]*(?:[ \t]+\p{Lu}[\p{L}\p{N}&.,-]*){0,5}[ \t]+(?i:Ltd|Limited|PLC|LLC|Inc|Corporation)\b/u',
            $text, $matches);

        return array_values(array_unique(array_map('trim', $matches[0])));
    }

    private function containsName(string $text, string $name): bool
    {
        return preg_match('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/iu', $text) === 1;
    }

    private function sameName(string $left, string $right): bool
    {
        return mb_strtolower(rtrim(trim($left), '.')) === mb_strtolower(rtrim(trim($right), '.'));
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
