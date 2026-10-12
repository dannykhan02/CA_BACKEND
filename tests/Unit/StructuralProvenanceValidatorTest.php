<?php

namespace Tests\Unit;

use App\Services\Intelligence\StructuralClaimGuard;
use App\Services\Intelligence\StructuralProvenanceValidator;
use PHPUnit\Framework\TestCase;

class StructuralProvenanceValidatorTest extends TestCase
{
    private function graph(): array
    {
        $nodes = [];
        foreach (['a' => 'table', 'b' => 'table', 'v' => 'cell', 'v2' => 'cell',
            'revenue' => 'row_header', 'expenses' => 'row_header',
            'fy24' => 'column_header', 'fy25' => 'column_header', 'unit' => 'caption'] as $key => $kind) {
            $nodes[] = ['key' => $key, 'kind' => $kind];
        }
        $edges = [
            ['from' => 'v', 'to' => 'a', 'type' => 'table_parent'],
            ['from' => 'v2', 'to' => 'b', 'type' => 'table_parent'],
            ['from' => 'revenue', 'to' => 'a', 'type' => 'table_parent'],
            ['from' => 'expenses', 'to' => 'b', 'type' => 'table_parent'],
            ['from' => 'fy24', 'to' => 'a', 'type' => 'table_parent'],
            ['from' => 'fy25', 'to' => 'a', 'type' => 'table_parent'],
            ['from' => 'v', 'to' => 'revenue', 'type' => 'row_header'],
            ['from' => 'v', 'to' => 'fy25', 'type' => 'column_header'],
            ['from' => 'a', 'to' => 'unit', 'type' => 'caption'],
        ];

        return [$nodes, $edges];
    }

    public function test_value_row_column_and_table_unit_form_one_valid_fact(): void
    {
        [$nodes, $edges] = $this->graph();
        (new StructuralProvenanceValidator)->validate($nodes, $edges, [
            ['evidence_id' => 'one', 'anchor' => 'v', 'role' => 'value'],
            ['evidence_id' => 'one', 'anchor' => 'revenue', 'role' => 'metric'],
            ['evidence_id' => 'one', 'anchor' => 'fy25', 'role' => 'period'],
            ['evidence_id' => 'one', 'anchor' => 'unit', 'role' => 'scale'],
        ]);
        self::assertTrue(true);
    }

    public function test_wrong_column_cannot_use_textual_fallback(): void
    {
        $record = ['source_provenance' => ['roles' => [
            'value' => [['text' => '12.4']], 'period' => [['text' => 'FY2025']],
            'metric' => [['text' => 'Revenue', 'kind' => 'row_header']],
        ]]];
        self::assertFalse((new StructuralClaimGuard)->compatible('FY2024 revenue was 12.4', $record));
        self::assertTrue((new StructuralClaimGuard)->compatible('FY2025 revenue was 12.4', $record));
        self::assertFalse((new StructuralClaimGuard)->compatible('FY2025 employees were 12.4', $record));
        self::assertTrue((new StructuralClaimGuard)->compatible('FY2024 revenue was 12.4', []));
        $record['source_provenance']['roles']['unit'] = [['text' => 'USD']];
        self::assertFalse((new StructuralClaimGuard)->compatible('FY2025 revenue was KES 12.4', $record));
        self::assertTrue((new StructuralClaimGuard)->compatible('FY2025 revenue was USD 12.4', $record));
    }

    public function test_wrong_row_and_cross_table_roles_are_rejected(): void
    {
        [$nodes, $edges] = $this->graph();
        $nodes[] = ['key' => 'other_row', 'kind' => 'row_header'];
        $edges[] = ['from' => 'other_row', 'to' => 'a', 'type' => 'table_parent'];
        foreach ([['expenses', 'metric'], ['other_row', 'metric'], ['fy24', 'period']] as [$anchor, $role]) {
            try {
                (new StructuralProvenanceValidator)->validate($nodes, $edges, [
                    ['evidence_id' => 'one', 'anchor' => 'v', 'role' => 'value'],
                    ['evidence_id' => 'one', 'anchor' => $anchor, 'role' => $role],
                ]);
                self::fail('Unrelated table role was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_multi_level_header_path_is_explicit(): void
    {
        [$nodes, $edges] = $this->graph();
        $nodes[] = ['key' => 'q2', 'kind' => 'column_header'];
        $edges[] = ['from' => 'q2', 'to' => 'a', 'type' => 'table_parent'];
        $edges[] = ['from' => 'v', 'to' => 'q2', 'type' => 'column_header'];
        $edges[] = ['from' => 'q2', 'to' => 'fy25', 'type' => 'column_header'];
        (new StructuralProvenanceValidator)->validate($nodes, $edges, [
            ['evidence_id' => 'one', 'anchor' => 'v', 'role' => 'value'],
            ['evidence_id' => 'one', 'anchor' => 'q2', 'role' => 'period'],
            ['evidence_id' => 'one', 'anchor' => 'fy25', 'role' => 'period'],
        ]);
        self::assertTrue(true);
    }

    public function test_parent_cycle_is_rejected(): void
    {
        [$nodes, $edges] = $this->graph();
        $edges[] = ['from' => 'a', 'to' => 'v', 'type' => 'contained_by'];
        $this->expectException(\InvalidArgumentException::class);
        (new StructuralProvenanceValidator)->validate($nodes, $edges, []);
    }

    public function test_cross_page_header_requires_adjacent_explicit_continuation(): void
    {
        [$nodes, $edges] = $this->graph();
        foreach ($nodes as &$node) {
            if ($node['key'] === 'v') {
                $node['page'] = 2;
            }
            if ($node['key'] === 'fy25') {
                $node['page'] = 1;
            }
        }
        unset($node);
        $bindings = [
            ['evidence_id' => 'one', 'anchor' => 'v', 'role' => 'value'],
            ['evidence_id' => 'one', 'anchor' => 'fy25', 'role' => 'period'],
        ];
        try {
            (new StructuralProvenanceValidator)->validate($nodes, $edges, $bindings);
            self::fail('Uncontinued header crossed a page.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
        $edges[] = ['from' => 'fy25', 'to' => 'v', 'type' => 'continuation'];
        (new StructuralProvenanceValidator)->validate($nodes, $edges, $bindings);
        self::assertTrue(true);
        foreach ($nodes as &$node) {
            if ($node['key'] === 'v') {
                $node['page'] = 3;
            }
        }
        unset($node);
        $this->expectException(\InvalidArgumentException::class);
        (new StructuralProvenanceValidator)->validate($nodes, $edges, $bindings);
    }
}
