<?php

namespace App\Services\Intelligence;

use App\Services\Extraction\StructuredDocument;

/** Narrow shadow matcher over already projected PDF table cells. No unit or column proof. */
final class ModelChartRowMatcher
{
    public function match(StructuredDocument $source, array $points, ?int $page = null): array
    {
        if ($source->format !== 'PDF' || ! str_contains($source->parserVersion, '+basic-grid.v1')) {
            return array_fill(0, count($points), ['status' => 'no_match']);
        }
        $tables = [];
        foreach ($source->nodes as $node) {
            if (($node['type'] ?? null) === 'table'
                && ($node['meta']['structural_type'] ?? null) === 'basic_relational'
                && ($page === null || ($node['page'] ?? null) === $page)) {
                $tables[$node['id']] = ['node' => $node, 'rows' => []];
            }
        }
        foreach ($source->nodes as $node) {
            if (($node['type'] ?? null) !== 'cell' || ($node['meta']['header'] ?? false)
                || ! isset($node['parent'], $node['text'])) {
                continue;
            }
            foreach ($tables as $id => &$table) {
                if (str_starts_with($node['parent'], $id.'/row[')) {
                    $table['rows'][$node['parent']][] = $node;
                    break;
                }
            }
            unset($table);
        }
        // A candidate is credible only when multiple proposed category labels occur in its
        // projected rows, or a real page reference scopes the search to this table's page.
        $labels = array_map(fn ($point) => $this->label($point['label'] ?? ''), $points);
        $tables = array_filter($tables, function ($table) use ($labels, $page) {
            if (count($table['rows']) < 2) {
                return false;
            }
            $hits = 0;
            foreach ($labels as $label) {
                if ($label === '') {
                    continue;
                }
                foreach ($table['rows'] as $cells) {
                    if (count(array_filter($cells, fn ($cell) => $this->label($cell['text']) === $label)) > 0) {
                        $hits++;
                        break;
                    }
                }
            }

            return $hits >= ($page === null ? 2 : 1);
        });
        $results = [];
        foreach ($points as $point) {
            if (! is_array($point) || ! empty($point['derived']) || ! empty($point['calculated'])) {
                $results[] = ['status' => 'no_match'];

                continue;
            }
            if (isset($point['page']) && (! is_int($point['page']) || $point['page'] < 1
                || ($page !== null && $point['page'] !== $page))) {
                $results[] = ['status' => 'no_match'];

                continue;
            }
            $label = $this->label($point['label'] ?? '');
            $value = $this->number($point['value'] ?? null);
            $relationships = [];
            $figures = 0;
            $labelRows = [];
            $crossedLabel = false;
            foreach ($tables as $table) {
                if (isset($point['page']) && $table['node']['page'] !== $point['page']) {
                    continue;
                }
                foreach ($table['rows'] as $rowId => $cells) {
                    $labelCells = array_values(array_filter($cells,
                        fn ($cell) => $this->label($cell['text']) === $label));
                    $numeric = array_values(array_filter($cells,
                        fn ($cell) => $this->number($cell['text']) !== null));
                    $matching = array_values(array_filter($numeric,
                        fn ($cell) => $this->number($cell['text']) === $value));
                    $figures += count($matching);
                    if ($labelCells !== []) {
                        $labelRows[] = ['numeric' => $numeric, 'labels' => $labelCells];
                    }
                    foreach ($labelCells as $category) {
                        foreach ($matching as $figure) {
                            $a = $category['meta']['bbox'] ?? null;
                            $b = $figure['meta']['bbox'] ?? null;
                            if (! is_array($a) || ! is_array($b)) {
                                continue;
                            }
                            if ($a[2] >= $b[0] || abs($a[1] - $b[1]) > 4
                                || ! isset($category['start'], $figure['start'])) {
                                continue;
                            }
                            foreach ($cells as $between) {
                                $box = $between['meta']['bbox'] ?? null;
                                if (! is_array($box)) {
                                    continue;
                                }
                                if ($between['id'] !== $category['id'] && $between['id'] !== $figure['id']
                                    && $box[0] >= $a[2] && $box[2] <= $b[0]
                                    && $this->number($between['text']) === null) {
                                    $crossedLabel = true;
                                }
                            }
                            $relationships[] = ['table_id' => $table['node']['id'], 'row_id' => $rowId,
                                'page' => $figure['page'], 'label_text' => $category['text'],
                                'figure_text' => $figure['text'], 'start' => $figure['start'],
                                'end' => $figure['end']];
                        }
                    }
                }
            }
            $single = count($labelRows) === 1 && count($labelRows[0]['numeric']) === 1
                && count($labelRows[0]['labels']) === 1;
            $categoryBox = $single ? ($labelRows[0]['labels'][0]['meta']['bbox'] ?? null) : null;
            $numberBox = $single ? ($labelRows[0]['numeric'][0]['meta']['bbox'] ?? null) : null;
            $directSingle = is_array($categoryBox) && is_array($numberBox)
                && $categoryBox[2] < $numberBox[0]
                && abs($categoryBox[1] - $numberBox[1]) <= 4;
            if (count($relationships) === 1 && count($labelRows) === 1 && $figures === 1 && ! $crossedLabel) {
                $results[] = ['status' => 'unique_row_match', 'source' => $relationships[0]];
            } elseif (count($relationships) > 0 || count($labelRows) > 1) {
                $results[] = ['status' => 'ambiguous'];
            } elseif ($directSingle && $this->number($labelRows[0]['numeric'][0]['text']) !== $value) {
                $results[] = ['status' => 'contradiction',
                    'printed_value' => $labelRows[0]['numeric'][0]['text']];
            } elseif ($figures > 0) {
                $results[] = ['status' => 'figure_without_relationship'];
            } else {
                $results[] = ['status' => 'no_match'];
            }
        }

        return $results;
    }

    private function label(mixed $text): string
    {
        return is_string($text) ? mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text))) : '';
    }

    /** Exact decimal equality after separators and sign notation; never fuzzy or scaled. */
    private function number(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            if (! is_finite((float) $value)) {
                return null;
            }
            $value = (string) $value;
        }
        if (! is_string($value)) {
            return null;
        }
        $raw = trim($value);
        if (! preg_match('/^(\()?(-)?\s*(?:[$€£]|[A-Z]{3}\s+)?\s*(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d+))?\s*%?\s*(\))?$/uD',
            $raw, $m) || (($m[1] ?? '') !== '') !== (($m[5] ?? '') !== '')) {
            return null;
        }
        $whole = ltrim(str_replace(',', '', $m[3]), '0');
        $decimal = rtrim($m[4] ?? '', '0');

        return (($m[1] ?? '') === '(' || ($m[2] ?? '') === '-' ? '-' : '')
            .($whole === '' ? '0' : $whole).($decimal === '' ? '' : '.'.$decimal);
    }
}
