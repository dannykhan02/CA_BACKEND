<?php

namespace App\Services\Intelligence;

/** Validates one parser-supplied graph before it can be published as field-role proof. */
class StructuralProvenanceValidator
{
    public function validate(array $anchors, array $relations, array $bindings): void
    {
        $nodes = [];
        foreach ($anchors as $anchor) {
            $nodes[$anchor['key']] = $anchor;
        }
        $out = [];
        $captionOwner = [];
        foreach ($relations as $edge) {
            if (! isset($nodes[$edge['from']], $nodes[$edge['to']]) || $edge['from'] === $edge['to']) {
                throw new \InvalidArgumentException('Structural relationship has no distinct source endpoints.');
            }
            $out[$edge['from']][$edge['type']][] = $edge['to'];
            if ($edge['type'] === 'caption') {
                if (isset($captionOwner[$edge['to']]) && $captionOwner[$edge['to']] !== $edge['from']) {
                    throw new \InvalidArgumentException('Caption has multiple table owners.');
                }
                $captionOwner[$edge['to']] = $edge['from'];
            }
        }
        $hierarchy = ['contained_by', 'row_header', 'column_header', 'table_parent', 'section_parent'];
        $state = [];
        $visit = function (string $key) use (&$visit, &$state, $out, $hierarchy): void {
            if (($state[$key] ?? 0) === 1) {
                throw new \InvalidArgumentException('Structural parent cycle.');
            }
            if (($state[$key] ?? 0) === 2) {
                return;
            }
            $state[$key] = 1;
            foreach ($hierarchy as $type) {
                foreach ($out[$key][$type] ?? [] as $parent) {
                    $visit($parent);
                }
            }
            $state[$key] = 2;
        };
        foreach (array_keys($nodes) as $key) {
            $visit($key);
        }
        foreach ($relations as $edge) {
            if ($edge['type'] !== 'continuation') {
                continue;
            }
            $fromPage = $nodes[$edge['from']]['page'] ?? null;
            $toPage = $nodes[$edge['to']]['page'] ?? null;
            if (! is_int($fromPage) || ! is_int($toPage) || abs($fromPage - $toPage) !== 1
                || $this->tableOf($edge['from'], $nodes, $out) === null
                || $this->tableOf($edge['from'], $nodes, $out)
                    !== $this->tableOf($edge['to'], $nodes, $out)) {
                throw new \InvalidArgumentException('Continuation needs adjacent pages in one proven table.');
            }
        }
        $byEvidence = [];
        foreach ($bindings as $binding) {
            $byEvidence[$binding['evidence_id']][$binding['role']][] = $binding['anchor'];
        }
        foreach ($byEvidence as $roles) {
            $values = $roles['value'] ?? [];
            if (count($values) !== 1) {
                // Multi-number cells require a unique value subspan for each fact.
                throw new \InvalidArgumentException('A structural fact needs one value occurrence.');
            }
            $value = $values[0];
            $valueNode = $nodes[$value] ?? null;
            if ($valueNode === null) {
                throw new \InvalidArgumentException('Value anchor is missing.');
            }
            $table = $this->tableOf($value, $nodes, $out);
            foreach ($roles as $role => $keys) {
                foreach ($keys as $key) {
                    if (! isset($nodes[$key])) {
                        throw new \InvalidArgumentException('Role anchor is missing.');
                    }
                    if ($key === $value) {
                        continue;
                    }
                    $valuePage = $valueNode['page'] ?? null;
                    $rolePage = $nodes[$key]['page'] ?? null;
                    if (is_int($valuePage) && is_int($rolePage) && $valuePage !== $rolePage
                        && ! in_array($key, $out[$value]['continuation'] ?? [], true)
                        && ! in_array($value, $out[$key]['continuation'] ?? [], true)) {
                        throw new \InvalidArgumentException('Cross-page role lacks explicit continuation.');
                    }
                    if ($table !== null) {
                        $rowPath = $this->headerPath($value, 'row_header', $out);
                        $columnPath = $this->headerPath($value, 'column_header', $out);
                        $direct = in_array($key, $rowPath, true) || in_array($key, $columnPath, true)
                            || in_array($key, $out[$value]['footnote'] ?? [], true);
                        $tableCaption = in_array($key, $out[$table]['caption'] ?? [], true);
                        if (! $direct && ! $tableCaption) {
                            throw new \InvalidArgumentException('Field role is outside the value table.');
                        }
                        if (! $tableCaption && $this->tableOf($key, $nodes, $out) !== $table) {
                            throw new \InvalidArgumentException('Field role belongs to another table.');
                        }
                        if (in_array($role, ['metric', 'subject'], true) && ! in_array($key, $rowPath, true)) {
                            throw new \InvalidArgumentException('Metric lacks a value-to-row relationship.');
                        }
                        if ($role === 'period' && ! in_array($key, $columnPath, true)) {
                            throw new \InvalidArgumentException('Period lacks a value-to-column relationship.');
                        }
                        if (in_array($role, ['currency', 'unit', 'scale'], true)
                            && ! $tableCaption && ! in_array($key, $columnPath, true)) {
                            throw new \InvalidArgumentException('Unit lacks a table caption or column-header relationship.');
                        }
                    } elseif (! $this->sameTextContainer($value, $key, $out)) {
                        throw new \InvalidArgumentException('Prose roles have no common source container.');
                    }
                }
            }
        }
    }

    /** @return list<string> */
    private function headerPath(string $value, string $type, array $out): array
    {
        $seen = [];
        $frontier = [$value];
        for ($depth = 0; $depth < 4 && $frontier !== []; $depth++) {
            $next = [];
            foreach ($frontier as $from) {
                foreach ($out[$from][$type] ?? [] as $to) {
                    if (! isset($seen[$to])) {
                        $seen[$to] = true;
                        $next[] = $to;
                    }
                }
            }
            $frontier = $next;
        }

        return array_keys($seen);
    }

    private function tableOf(string $key, array $nodes, array $out): ?string
    {
        if (($nodes[$key]['kind'] ?? null) === 'table') {
            return $key;
        }
        // Two hops allow a numeric subspan inside a cell, then its table. No arbitrary traversal.
        foreach ($out[$key]['table_parent'] ?? [] as $parent) {
            if (($nodes[$parent]['kind'] ?? null) === 'table') {
                return $parent;
            }
        }
        foreach ($out[$key]['contained_by'] ?? [] as $container) {
            foreach ($out[$container]['table_parent'] ?? [] as $parent) {
                if (($nodes[$parent]['kind'] ?? null) === 'table') {
                    return $parent;
                }
            }
        }

        return null;
    }

    private function sameTextContainer(string $left, string $right, array $out): bool
    {
        return array_intersect($out[$left]['contained_by'] ?? [], $out[$right]['contained_by'] ?? []) !== [];
    }
}
