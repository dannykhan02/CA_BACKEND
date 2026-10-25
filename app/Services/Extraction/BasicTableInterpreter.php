<?php

namespace App\Services\Extraction;

use App\Services\Intelligence\PeriodParser;

/** A read model for exact basic grids. It never grants evidence or chart trust. */
final class BasicTableInterpreter
{
    public function __construct(private PeriodParser $periods) {}

    public function interpret(StructuredDocument $source, string $tableId): ?array
    {
        $nodes = array_column($source->nodes, null, 'id');
        $table = $nodes[$tableId] ?? null;
        if (($table['type'] ?? null) !== 'table'
            || ($table['meta']['structural_type'] ?? null) !== 'basic_relational') {
            return null;
        }
        $header = [];
        $rows = [];
        foreach ($source->nodes as $node) {
            if (($node['type'] ?? null) !== 'cell'
                || ! str_starts_with($node['id'], $tableId.'/row[')) {
                continue;
            }
            $column = ($node['meta']['grid_column'] ?? 0) - 1;
            if ($column < 0 || ! isset($node['start'], $node['end'], $node['meta']['bbox'])) {
                return null;
            }
            if (($node['meta']['header'] ?? false) === true) {
                $header[$column] = $node;
            } else {
                $rowId = $node['parent'];
                $rows[$rowId][$column] = $node;
            }
        }
        ksort($header);
        if (count($header) < 2 || count($rows) < 2) {
            return null;
        }
        $columns = [];
        $caption = $nodes[$table['meta']['caption_id'] ?? ''] ?? null;
        foreach ($header as $index => $cell) {
            $name = trim($cell['text']);
            $unit = $this->headerUnit($name);
            $scale = $this->scale($name, (string) ($caption['text'] ?? ''));
            $columns[$index] = ['index' => $index, 'header_cell_id' => $cell['id'],
                'header_path_cell_ids' => [$cell['id']],
                'raw_header' => $cell['text'], 'unit' => $unit,
                'scale' => $scale['value'], 'scale_source_cell_id' => $scale['source'] === 'header'
                    ? $cell['id'] : ($scale['source'] === 'caption' ? ($caption['id'] ?? null) : null),
                'role' => $this->role($name, $index, $unit)];
        }
        $normalizedRows = [];
        $labels = [];
        foreach ($rows as $rowId => $cells) {
            ksort($cells);
            if (! isset($cells[0])) {
                return null;
            }
            $label = trim($cells[0]['text']);
            $aggregate = preg_match('/^(?:sub\s*total|total|grand\s*total)\b/iu', $label) === 1;
            $values = [];
            foreach ($header as $index => $_heading) {
                $cell = $cells[$index] ?? null;
                if ($cell === null) {
                    if (! $aggregate) {
                        return null;
                    }
                    $values[] = ['cell_id' => null, 'column' => $index,
                        'raw_text' => '', 'parsed_value' => null, 'data_type' => 'blank',
                        'unit' => $columns[$index]['unit'],
                        'numeric_scale' => $columns[$index]['scale'], 'value_status' => 'unclassified',
                        'footnote_refs' => [], 'semantic_role' => $columns[$index]['role'],
                        'source' => null];
                    continue;
                }
                $raw = trim($cell['text']);
                $parsed = $this->number($raw);
                $values[] = ['cell_id' => $cell['id'], 'column' => $index,
                    'raw_text' => $cell['text'], 'parsed_value' => $parsed,
                    'data_type' => $parsed === null ? 'text' : 'number',
                    'unit' => $columns[$index]['unit'], 'numeric_scale' => $columns[$index]['scale'],
                    'value_status' => 'unclassified', 'footnote_refs' => [],
                    'semantic_role' => $columns[$index]['role'],
                    'source' => ['page' => $cell['page'], 'start' => $cell['start'],
                        'end' => $cell['end'], 'bbox' => $cell['meta']['bbox']]];
            }
            $normalizedRows[] = ['row_id' => $rowId, 'raw_label' => $cells[0]['text'],
                'row_path_cell_ids' => [$cells[0]['id']],
                'aggregation_role' => $aggregate ? 'reported_total' : 'detail', 'cells' => $values];
            if (! $aggregate) {
                $labels[] = $label;
            }
        }
        $potential = [];
        $checks = [];
        $conflict = false;
        $totals = array_values(array_filter($normalizedRows,
            static fn ($row) => $row['aggregation_role'] === 'reported_total'));
        $detail = array_values(array_filter($normalizedRows,
            static fn ($row) => $row['aggregation_role'] === 'detail'));
        if (count($totals) > 1) {
            $conflict = true;
            $checks[] = ['code' => 'multiple_reported_totals', 'status' => 'ambiguous'];
        } elseif (count($totals) === 1) {
            foreach ($columns as $index => $column) {
                if (! in_array($column['role'], ['amount', 'percentage'], true)) {
                    continue;
                }
                $reported = $totals[0]['cells'][$index]['parsed_value'];
                $parts = array_column(array_map(static fn ($row) => $row['cells'][$index],
                    $detail), 'parsed_value');
                if ($reported === null || in_array(null, $parts, true)) {
                    $checks[] = ['code' => 'total_not_checkable', 'column' => $index,
                        'status' => 'unverified'];
                    continue;
                }
                $tolerance = $column['role'] === 'percentage'
                    ? max(0.1, count($parts) * 0.05) : 0.5;
                $matches = abs(array_sum($parts) - $reported) <= $tolerance;
                $checks[] = ['code' => 'reported_total', 'column' => $index,
                    'status' => $matches ? 'arithmetically_consistent' : 'conflict',
                    'reported' => $reported, 'computed' => array_sum($parts),
                    'tolerance' => $tolerance];
                $conflict = $conflict || ! $matches;
            }
            $amountColumns = array_keys(array_filter($columns,
                static fn ($column) => $column['role'] === 'amount'));
            $shareColumns = array_keys(array_filter($columns,
                static fn ($column) => $column['role'] === 'percentage'));
            if (count($amountColumns) === 1 && count($shareColumns) === 1) {
                $amountColumn = $amountColumns[0];
                $shareColumn = $shareColumns[0];
                $denominator = $totals[0]['cells'][$amountColumn]['parsed_value'];
                if ($denominator === null || $denominator <= 0) {
                    $checks[] = ['code' => 'percentage_share', 'status' => 'unverified'];
                } else {
                    foreach ($detail as $row) {
                        $amount = $row['cells'][$amountColumn]['parsed_value'];
                        $share = $row['cells'][$shareColumn]['parsed_value'];
                        $rawShare = $row['cells'][$shareColumn]['raw_text'];
                        preg_match('/\.(\d+)/', $rawShare, $precision);
                        $tolerance = 0.5 * (10 ** -strlen($precision[1] ?? '')) + 0.001;
                        $matches = $amount !== null && $share !== null
                            && abs($amount / $denominator * 100 - $share) <= $tolerance;
                        $checks[] = ['code' => 'percentage_share', 'status' => $matches
                            ? 'arithmetically_consistent' : 'conflict', 'tolerance' => $tolerance];
                        $conflict = $conflict || ! $matches;
                    }
                }
            }
        }
        $unsafeStatus = preg_match('/\b(?:estimated|provisional|forecast|planned)\b/iu',
            implode(' ', array_merge([$caption['text'] ?? ''], array_column($header, 'text'),
                array_column($normalizedRows, 'raw_label')))) === 1;
        if (($table['meta']['layout_status'] ?? null) === 'structurally_reconstructed'
            && ! ($table['meta']['caption_ambiguous'] ?? false)
            && ! $unsafeStatus && count($labels) >= 2
            && count(array_unique($labels)) === count($labels)) {
            foreach ($columns as $index => $column) {
                if ($conflict || ! in_array($column['role'], ['amount', 'measure', 'count'], true)
                    || $column['unit'] === null || $column['scale'] === null) {
                    continue;
                }
                if (count(array_filter($detail, static fn ($row) =>
                    $row['cells'][$index]['parsed_value'] !== null)) === count($detail)) {
                    $potential[] = ['value_column' => $index, 'category_column' => 0,
                        'chart_types' => ['bar'], 'status' => 'requires_evidence_verification'];
                }
            }
        }
        $period = null;
        $periodSource = null;
        if (is_string($caption['text'] ?? null)
            && preg_match_all('/\b(?:19|20)\d{2}\/\d{2}\b/u', $caption['text'], $matches) === 1) {
            $periodSource = $matches[0][0];
            $period = $this->periods->parse($matches[0][0])?->label;
        }
        $analyticalStatus = ($table['meta']['layout_status'] ?? null) !== 'structurally_reconstructed'
            ? 'blocked_partial_reconstruction' : ($unsafeStatus ? 'blocked_value_status'
                : (($table['meta']['caption_ambiguous'] ?? false) ? 'blocked_ambiguous_context'
                    : ($conflict ? 'blocked_conflicting_total'
                        : ($potential === [] ? 'not_eligible_or_ambiguous' : 'potential_only'))));

        return ['table_id' => $tableId, 'source_revision' => hash('sha256', $source->text),
            'parser_version' => $source->parserVersion,
            'page_range' => [$table['page'], $table['page']],
            'caption' => $caption['text'] ?? null, 'caption_cell_id' => $caption['id'] ?? null,
            'period' => $period, 'period_source_cell_id' => $period === null ? null : $caption['id'],
            'period_source_text' => $periodSource,
            'structural_type' => 'basic_relational', 'columns' => array_values($columns),
            'rows' => $normalizedRows, 'structural_status' => ($table['meta']['layout_status'] ?? null)
                === 'structurally_reconstructed' ? 'reconstructed_exact' : 'partial',
            'semantic_status' => 'interpreted_basic_roles', 'evidence_status' => 'unverified',
            'analytical_status' => $analyticalStatus,
            'arithmetic_checks' => $checks, 'potential_charts' => $potential];
    }

    private function role(string $header, int $index, ?string $unit): string
    {
        if ($index === 0) {
            return 'category';
        }
        if (preg_match('/\b(?:target|threshold|limit|range|reference|budget|forecast)\b/iu', $header)) {
            return 'comparator';
        }
        if ($unit === '%') {
            return 'percentage';
        }
        if (in_array($unit, ['$', '€', '£'], true)
            || ($unit !== null && preg_match('/^[A-Z]{3}$/D', $unit))) {
            return 'amount';
        }
        if ($unit === 'count') {
            return 'count';
        }
        if ($unit !== null) {
            return 'measure';
        }

        return 'attribute_or_measure_unresolved';
    }

    private function headerUnit(string $header): ?string
    {
        if (preg_match('/\(\s*%\s*\)/u', $header)) {
            return '%';
        }
        if (preg_match('/\(\s*([$€£]|[A-Z]{3})\s*(?:in\s+)?(?:thousands?|millions?|billions?)?\s*\)/u',
            $header, $match)) {
            return $match[1];
        }
        if (preg_match('/\(\s*(kg|g|t|ha|mm|cm|m|l|kwh|t\/ha)\s*\)/iu', $header, $match)) {
            return $match[1];
        }
        if (preg_match('/\b(?:count|number)\b/iu', $header)) {
            return 'count';
        }

        return null;
    }

    /** No conversion: the scale only labels how the printed number is expressed. */
    private function scale(string $header, string $caption): array
    {
        $found = [];
        foreach (['header' => $header, 'caption' => $caption] as $source => $text) {
            if (preg_match_all('/\b(thousands?|millions?|billions?)\b/iu', $text, $matches)) {
                foreach ($matches[1] as $word) {
                    $found[] = ['value' => match (strtolower(rtrim($word, 's'))) {
                        'thousand' => 1000, 'million' => 1000000, 'billion' => 1000000000,
                    }, 'source' => $source];
                }
            }
        }
        if ($found === []) {
            return ['value' => 1, 'source' => null];
        }
        if (count(array_unique(array_column($found, 'value'))) !== 1) {
            return ['value' => null, 'source' => null];
        }

        return $found[0];
    }

    private function number(string $raw): ?float
    {
        if (! preg_match('/^(\(?-?\d{1,3}(?:,\d{3})*(?:\.\d+)?\)?|\(?-?\d+(?:\.\d+)?\)?)%?$/D',
            $raw)) {
            return null;
        }
        $negative = str_starts_with($raw, '(');
        $number = (float) str_replace([',', '(', ')', '%'], '', $raw);

        return $negative ? -$number : $number;
    }
}
