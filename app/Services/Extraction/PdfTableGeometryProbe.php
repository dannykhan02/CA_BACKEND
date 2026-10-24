<?php

namespace App\Services\Extraction;

use App\Services\Intelligence\PeriodParser;
use Symfony\Component\Process\Process;

/**
 * Conservative, offline layout probe. It does not publish provenance: a grid is
 * only a candidate until its words can be projected onto the stored text and
 * its governing entity, basis and unit have independent source proof.
 */
final class PdfTableGeometryProbe
{
    public function __construct(private PeriodParser $periods) {}

    /** @return list<array<string,mixed>> */
    public function extract(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new \InvalidArgumentException('PDF is not readable.');
        }
        $process = new Process(['pdftotext', '-bbox-layout', $path, '-']);
        $process->setTimeout(30);
        $process->mustRun();
        $xml = $process->getOutput();
        if (strlen($xml) > 30 * 1024 * 1024) {
            throw new \RuntimeException('PDF word layout exceeds probe limit.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument;
            if (! $dom->loadXML($xml, LIBXML_NONET) || $dom->doctype === null
                || $dom->documentElement?->localName !== 'html') {
                throw new \RuntimeException('Invalid Poppler word layout.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new \DOMXPath($dom);
        $pages = $xpath->query('//*[local-name()="page"]');
        $tables = [];
        $hash = hash_file('sha256', $path);
        foreach ($pages as $pageIndex => $page) {
            $lines = $this->lines($xpath, $page);
            foreach ($lines as $index => $line) {
                $header = $this->header($lines, $index);
                if ($header === null) {
                    continue;
                }
                [$headers, $periods, $paths, $spanning] = $header;
                $heading = $this->heading($lines, $index);
                // A year printed below a cumulative or spanning heading is not
                // evidence of a quarterly period. Keep the raw header and reject.
                if (! $spanning && $heading['period_scope_ambiguous']) {
                    continue;
                }
                $rows = [];
                $lastRowY = $line['y'];
                for ($next = $index + 1; $next < count($lines) && count($rows) < 100; $next++) {
                    if ($lines[$next]['y'] - $lastRowY > 45) {
                        break;
                    }
                    // A standalone note marker starts a subordinate exhibit. Do
                    // not silently treat its reused columns as the same table.
                    if (count($rows) >= 2
                        && preg_match('/^(?:\(\d{1,2}\)(?:\s+\p{L}.*)?|\d{1,2})$/uD',
                            trim($lines[$next]['text'])) === 1) {
                        break;
                    }
                    $row = $this->numericRow($lines[$next], $headers);
                    if ($row === null) {
                        continue;
                    }
                    $rows[] = $row;
                    $lastRowY = $lines[$next]['y'];
                }
                if (count($rows) < 2) {
                    continue;
                }
                $allBoxes = array_values(array_filter(array_merge($headers, ...$rows),
                    static fn ($cell) => is_array($cell['bbox'] ?? null)));
                $tables[] = [
                    'page' => $pageIndex + 1,
                    'page_width' => (float) $page->attributes->getNamedItem('width')?->nodeValue,
                    'page_height' => (float) $page->attributes->getNamedItem('height')?->nodeValue,
                    'source_hash' => $hash,
                    'bbox' => $this->union($allBoxes),
                    'header' => array_map(static fn ($cell, $column) => [
                        'row' => 0, 'column' => $column, 'text' => $cell['text'],
                        'bbox' => $cell['bbox'], 'source_path' => $paths[$column] ?? [],
                    ], $headers, array_keys($headers)),
                    'periods' => $periods,
                    'heading' => $heading['text'],
                    'unit_source' => $heading['unit_source'],
                    'entity_source' => $heading['entity_source'],
                    'report_footer' => $this->reportFooter($lines, $pageIndex + 1),
                    'accounting_basis_source' => null,
                    'rows' => array_map(static fn ($row, $number) => array_map(
                        static fn ($cell, $column) => ['row' => $number + 1, 'column' => $column,
                            'text' => $cell['text'], 'bbox' => $cell['bbox'],
                            'currency_marker' => $cell['currency_marker'] ?? null],
                        $row, array_keys($row)), $rows, array_keys($rows)),
                    'status' => 'layout_candidate_only',
                ];
            }
        }

        return $tables;
    }

    /** @return ?array{array,array,array,bool} Grid columns and their source header paths. */
    private function header(array $lines, int $index): ?array
    {
        $groups = $this->columns($lines[$index]);
        if (count($groups) >= 3 && trim($groups[0]['text']) !== '') {
            $periods = [];
            $paths = [];
            foreach (array_slice($groups, 1) as $column => $cell) {
                $parsed = $this->periods->parse($cell['text']);
                if ($parsed !== null) {
                    $periods[$column + 1] = $parsed->label;
                    $paths[$column + 1] = [$cell];
                }
            }
            if (count($periods) >= 2 && count($periods) === count($groups) - 1
                && count(array_unique($periods)) === count($periods)) {
                return [$groups, $periods, $paths, false];
            }
        }
        // A year row beneath explicit duration spans is common in statements
        // of income. The span's actual x range must own each year column.
        if (count($groups) < 2 || count($groups) > 12
            || count(array_filter($groups, static fn ($cell) => preg_match('/^(?:19|20)\d{2}$/D',
                $cell['text']) === 1)) !== count($groups)) {
            return null;
        }
        $scope = [];
        $date = [];
        for ($previous = $index - 1; $previous >= 0 && $index - $previous <= 4; $previous--) {
            if ($lines[$index]['y'] - $lines[$previous]['y'] > 35) {
                break;
            }
            foreach ($this->durationSpans($lines[$previous]) as $span) {
                $scope[] = $span;
            }
            foreach ($this->columns($lines[$previous]) as $cell) {
                if (preg_match('/^[a-z]{3,9}\s+\d{1,2},?$/iuD', $cell['text'])) {
                    $date[] = $cell;
                }
            }
        }
        if ($scope === [] || $date === []) {
            return null;
        }
        $headers = [['text' => '', 'bbox' => null]];
        $periods = [];
        $paths = [];
        foreach ($groups as $column => $year) {
            $center = ($year['bbox'][0] + $year['bbox'][2]) / 2;
            $owners = array_values(array_filter($scope,
                static fn ($span) => $center >= $span['bbox'][0] - 8 && $center <= $span['bbox'][2] + 8));
            $dates = array_values(array_filter($date,
                static fn ($span) => $center >= $span['bbox'][0] - 20 && $center <= $span['bbox'][2] + 20));
            if (count($owners) !== 1 || count($dates) !== 1) {
                return null;
            }
            $raw = $owners[0]['text'].' '.$dates[0]['text'].' '.$year['text'];
            $parsed = $this->periods->parse($raw);
            if ($parsed === null) {
                return null;
            }
            $headers[] = ['text' => $year['text'], 'bbox' => $year['bbox']];
            $periods[$column + 1] = $parsed->label;
            $paths[$column + 1] = [$owners[0], $dates[0], $year];
        }
        if (count(array_unique($periods)) !== count($periods)) {
            return null;
        }

        return [$headers, $periods, $paths, true];
    }

    /** @return list<array{text:string,bbox:array}> */
    private function durationSpans(array $line): array
    {
        $spans = [];
        $words = $line['words'];
        for ($i = 0; $i + 2 < count($words); $i++) {
            if (preg_match('/^(?:Three|Six|Nine|Twelve)$/iuD', $words[$i]['text'])
                && preg_match('/^Months?$/iuD', $words[$i + 1]['text'])
                && preg_match('/^Ended$/iuD', $words[$i + 2]['text'])) {
                $spans[] = ['text' => implode(' ', array_column(array_slice($words, $i, 3), 'text')),
                    'bbox' => $this->union(array_slice($words, $i, 3))];
                $i += 2;
            }
        }

        return $spans;
    }

    /** @return list<array{words:list<array>,y:float,text:string}> */
    private function lines(\DOMXPath $xpath, \DOMNode $page): array
    {
        $words = [];
        foreach ($xpath->query('.//*[local-name()="word"]', $page) as $word) {
            $text = trim($word->textContent);
            if ($text === '') {
                continue;
            }
            $box = [(float) $word->attributes->getNamedItem('xMin')?->nodeValue,
                (float) $word->attributes->getNamedItem('yMin')?->nodeValue,
                (float) $word->attributes->getNamedItem('xMax')?->nodeValue,
                (float) $word->attributes->getNamedItem('yMax')?->nodeValue];
            if ($box[2] <= $box[0] || $box[3] <= $box[1]) {
                continue;
            }
            $words[] = ['text' => $text, 'bbox' => $box];
        }
        usort($words, static fn ($a, $b) => [$a['bbox'][1], $a['bbox'][0]] <=> [$b['bbox'][1], $b['bbox'][0]]);
        $lines = [];
        foreach ($words as $word) {
            $last = count($lines) - 1;
            if ($last < 0 || abs($word['bbox'][1] - $lines[$last]['y']) > 3) {
                $lines[] = ['words' => [], 'y' => $word['bbox'][1], 'text' => ''];
                $last++;
            }
            $lines[$last]['words'][] = $word;
        }
        foreach ($lines as &$line) {
            usort($line['words'], static fn ($a, $b) => $a['bbox'][0] <=> $b['bbox'][0]);
            $line['text'] = implode(' ', array_column($line['words'], 'text'));
        }
        unset($line);

        return $lines;
    }

    /** @return list<array{text:string,bbox:array}> */
    private function columns(array $line): array
    {
        $groups = [];
        foreach ($line['words'] as $word) {
            $last = count($groups) - 1;
            if ($last < 0 || $word['bbox'][0] - $groups[$last]['bbox'][2] > 18) {
                $groups[] = $word;
            } else {
                $groups[$last]['text'] .= ' '.$word['text'];
                $groups[$last]['bbox'] = $this->union([$groups[$last], $word]);
            }
        }

        return $groups;
    }

    private function numericRow(array $line, array $headers): ?array
    {
        if (count($headers) < 3) {
            return null;
        }
        $firstX = $headers[1]['bbox'][0];
        $labelWords = array_values(array_filter($line['words'],
            static fn ($word) => $word['bbox'][0] < $firstX - 25));
        if ($labelWords === []) {
            return null;
        }
        $label = ['text' => implode(' ', array_column($labelWords, 'text')),
            'bbox' => $this->union($labelWords)];
        if (preg_match('/\p{L}/u', $label['text']) !== 1) {
            return null;
        }
        $cells = [0 => $label];
        $currencyWords = [];
        foreach ($line['words'] as $word) {
            if (in_array($word['text'], ['$', '€', '£'], true)) {
                $currencyWords[] = $word;

                continue;
            }
            if ($word['bbox'][0] < $firstX - 25) {
                continue;
            }
            if (preg_match('/^\(?-?\d{1,3}(?:,\d{3})*(?:\.\d+)?\)?%?$|^-?\d+(?:\.\d+)?%?$/D',
                $word['text']) !== 1) {
                return null;
            }
            $distances = [];
            foreach (array_slice($headers, 1, null, true) as $column => $header) {
                $distances[$column] = abs(($word['bbox'][0] + $word['bbox'][2]) / 2
                    - ($header['bbox'][0] + $header['bbox'][2]) / 2);
            }
            asort($distances);
            $column = array_key_first($distances);
            if ($column === null || $distances[$column] > 35 || isset($cells[$column])) {
                return null;
            }
            $cells[$column] = $word;
        }
        if (count($cells) !== count($headers)) {
            return null;
        }
        foreach (array_slice($cells, 1, null, true) as $column => $cell) {
            $markers = array_values(array_filter($currencyWords,
                static fn ($word) => $word['bbox'][2] <= $cell['bbox'][0]
                    && $cell['bbox'][0] - $word['bbox'][2] <= 35
                    && abs($word['bbox'][1] - $cell['bbox'][1]) <= 3));
            if (count($markers) === 1) {
                $cells[$column]['currency_marker'] = $markers[0];
            }
        }

        ksort($cells);

        return array_values($cells);
    }

    private function heading(array $lines, int $headerIndex): array
    {
        $text = [];
        $unit = null;
        $ambiguous = false;
        $headerY = $lines[$headerIndex]['y'];
        $entity = null;
        for ($i = $headerIndex - 1; $i >= 0 && $headerY - $lines[$i]['y'] <= 100; $i--) {
            $candidate = $lines[$i]['text'];
            $text[] = $candidate;
            if (preg_match('/\b(?:thousands?|millions?|billions?)\b/iu', $candidate)) {
                $unit ??= $candidate;
            }
            if (preg_match('/\b(?:nine|six|twelve)\s+months?\s+ended\b/iu', $candidate)
                || preg_match('/\b(?:year|years)\s+(?:to\s+date|ended)\b/iu', $candidate)) {
                $ambiguous = true;
            }
            if (preg_match('/^[\p{L}\p{N} ,.\-&]+\b(?:Inc\.|Ltd\.?|PLC|Corporation)\s*$/iuD', $candidate)) {
                $entity ??= $candidate;
            }
        }

        return ['text' => implode("\n", array_reverse($text)),
            'unit_source' => $unit, 'entity_source' => $entity,
            'period_scope_ambiguous' => $ambiguous];
    }

    private function reportFooter(array $lines, int $pageNumber): ?array
    {
        $found = [];
        foreach ($lines as $line) {
            if (preg_match('/\b(?:Ltd\.?|Limited|Inc\.?|Corporation|PLC|LLC)\b.*\bPage\s+'
                .$pageNumber.'\s*$/iu', $line['text']) === 1) {
                $nameWords = [];
                foreach ($line['words'] as $word) {
                    if ($word['text'] === '|') {
                        break;
                    }
                    $nameWords[] = $word;
                }
                if ($nameWords !== []) {
                    $found[] = ['text' => $line['text'],
                        'bbox' => $this->union($nameWords)];
                }
            }
        }

        return count($found) === 1 ? $found[0] : null;
    }

    private function union(array $cells): array
    {
        return [min(array_map(static fn ($item) => $item['bbox'][0], $cells)),
            min(array_map(static fn ($item) => $item['bbox'][1], $cells)),
            max(array_map(static fn ($item) => $item['bbox'][2], $cells)),
            max(array_map(static fn ($item) => $item['bbox'][3], $cells))];
    }
}
