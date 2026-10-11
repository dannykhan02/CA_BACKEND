<?php

namespace App\Services\AI\Incremental;

use App\Services\Extraction\StructuredDocument;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/** Packs parser-proven small tables as units while retaining exact source span offsets. */
class StructuredChunkPlanner
{
    public const POLICY = 'structured-chunks.v1';

    public function plan(StructuredDocument $source, EvidenceSpanSet $spans, int $maximum,
        float $tokensPerByte = 1 / 3): array
    {
        $all = $spans->all();
        if ($all === []) {
            return [];
        }
        $maximum = max(1, $maximum);
        $tokensPerByte = min(1, max(0.1, $tokensPerByte));
        $tables = $this->tables($source);
        $groups = [];
        $tableAt = 0;
        foreach ($all as $index => $span) {
            while (isset($tables[$tableAt]) && $tables[$tableAt]['end'] <= $span['start_offset']) {
                $tableAt++;
            }
            $table = $tables[$tableAt] ?? null;
            $owner = $table !== null && $span['start_offset'] < $table['end']
                && $span['end_offset'] > $table['start'] ? $table['id'] : 'span:'.$index;
            $bytes = strlen(mb_substr($source->text, $span['start_offset'],
                $span['end_offset'] - $span['start_offset'])) + strlen($span['key']) + 5;
            $weight = max(1, (int) ceil($bytes * $tokensPerByte));
            if ($weight > $maximum) {
                throw new \RuntimeException('Source span exceeds structured chunk limit.');
            }
            $last = count($groups) - 1;
            if ($last >= 0 && $groups[$last]['owner'] === $owner) {
                $groups[$last]['last'] = $index;
                $groups[$last]['weight'] += $weight;
            } else {
                $groups[] = ['owner' => $owner, 'first' => $index, 'last' => $index, 'weight' => $weight];
            }
        }
        $units = [];
        foreach ($groups as $group) {
            if ($group['weight'] <= $maximum) {
                $units[] = $group;

                continue;
            }
            // A large table is split only at complete source-span boundaries. A span is a
            // source-backed row/paragraph unit; no synthetic copied header acquires an offset.
            for ($i = $group['first']; $i <= $group['last']; $i++) {
                $span = $all[$i];
                $bytes = strlen(mb_substr($source->text, $span['start_offset'],
                    $span['end_offset'] - $span['start_offset'])) + strlen($span['key']) + 5;
                $units[] = ['first' => $i, 'last' => $i,
                    'weight' => max(1, (int) ceil($bytes * $tokensPerByte))];
            }
        }
        $chunks = [];
        $first = null;
        $last = null;
        $used = 0;
        foreach ($units as $unit) {
            if ($first !== null && $used + $unit['weight'] > $maximum) {
                $chunks[] = $this->chunk($source->text, $all, $first, $last, $used);
                $first = null;
                $used = 0;
            }
            $first ??= $unit['first'];
            $last = $unit['last'];
            $used += $unit['weight'];
        }
        if ($first !== null) {
            $chunks[] = $this->chunk($source->text, $all, $first, $last, $used);
        }

        return $chunks;
    }

    private function chunk(string $text, array $spans, int $first, int $last, int $tokens): array
    {
        $start = $spans[$first]['start_offset'];
        $end = $spans[$last]['end_offset'];

        return ['start_offset' => $start, 'end_offset' => $end,
            'start_page' => $spans[$first]['page'], 'end_page' => $spans[$last]['page'],
            'token_count' => $tokens, 'input_hash' => hash('sha256', mb_substr($text, $start, $end - $start)),
            'overlap_chars' => 0];
    }

    private function tables(StructuredDocument $source): array
    {
        $tables = [];
        $cells = [];
        foreach ($source->nodes as $node) {
            if (($node['type'] ?? null) === 'table') {
                $tables[$node['id']] = ['id' => $node['id'], 'sheet' => $node['sheet'] ?? null,
                    'range' => $node['meta']['range'] ?? null, 'start' => PHP_INT_MAX, 'end' => 0];
            } elseif (($node['type'] ?? null) === 'cell' && isset($node['start'], $node['end'])) {
                $cells[] = $node;
            }
        }
        if (count($tables) > 1000 || count($cells) > 100000) {
            throw new \RuntimeException('Structured chunk table limit exceeded.');
        }
        $sheetTables = [];
        if ($source->format === 'XLSX') {
            foreach ($tables as $id => &$table) {
                if (is_string($table['range'])) {
                    $table['bounds'] = Coordinate::rangeBoundaries($table['range']);
                    $sheetTables[$table['sheet']][] = $id;
                }
            }
            unset($table);
        }
        foreach ($cells as $cell) {
            if ($source->format === 'DOCX') {
                if (preg_match('~^(.*?/tbl\[\d+\])(?:/tr\[\d+\]/tc\[\d+\])~', $cell['id'], $match)
                    && isset($tables[$match[1]])) {
                    $tables[$match[1]]['start'] = min($tables[$match[1]]['start'], $cell['start']);
                    $tables[$match[1]]['end'] = max($tables[$match[1]]['end'], $cell['end']);
                }

                continue;
            }
            if ($source->format !== 'XLSX') {
                continue;
            }
            $coordinate = $cell['meta']['coordinate'] ?? null;
            if (! is_string($coordinate)) {
                continue;
            }
            [$column, $row] = Coordinate::coordinateFromString($coordinate);
            $columnIndex = Coordinate::columnIndexFromString($column);
            foreach ($sheetTables[$cell['sheet'] ?? ''] ?? [] as $tableId) {
                $bounds = $tables[$tableId]['bounds'];
                if ($columnIndex >= $bounds[0][0] && $columnIndex <= $bounds[1][0]
                    && $row >= $bounds[0][1] && $row <= $bounds[1][1]) {
                    $tables[$tableId]['start'] = min($tables[$tableId]['start'], $cell['start']);
                    $tables[$tableId]['end'] = max($tables[$tableId]['end'], $cell['end']);
                }
            }
        }
        $tables = array_values(array_filter($tables, static fn ($table) => $table['end'] > $table['start']));
        usort($tables, static fn ($a, $b) => [$a['start'], $a['end'], $a['id']]
            <=> [$b['start'], $b['end'], $b['id']]);

        return $tables;
    }
}
