<?php

namespace Tests\Unit\Services;

use App\Services\Intelligence\XlsxCellWindowBuilder;
use DomainException;
use PHPUnit\Framework\TestCase;

class XlsxCellWindowBuilderTest extends TestCase
{
    private function source(): array
    {
        return ['matchState' => 'EXACT', 'quality' => ['conflictStatus' => null],
            'source' => ['range' => ['start' => 0, 'end' => 4]],
            'location' => ['id' => 'anchor-1', 'type' => 'worksheet_cell', 'modality' => 'xlsx',
                'sheet' => 'Results', 'path' => 'sheet:0!B2',
                'range' => ['start' => 0, 'end' => 4],
                'metadata' => ['coordinate' => 'B2', 'sheet_visibility' => 'visible']],
            'roles' => []];
    }

    private function nodes(): array
    {
        return [
            ['id' => 'sheet:0', 'type' => 'worksheet', 'sheet' => 'Results', 'path' => 'sheet:0',
                'meta' => ['visibility' => 'visible', 'date_system' => 1900]],
            ['id' => 'sheet:0/cell:B2', 'type' => 'cell', 'parent' => 'sheet:0',
                'sheet' => 'Results', 'path' => 'sheet:0!B2',
                'start' => 0, 'end' => 4, 'text' => '12.4',
                'meta' => ['coordinate' => 'B2', 'cell_type' => 'n', 'raw' => 12.4,
                    'display' => '12.4', 'formula' => null, 'cached_result' => null,
                    'cache_present' => false, 'number_format' => '0.0', 'rich_text' => false,
                    'merges' => [], 'sheet_visibility' => 'visible',
                    'row_visible' => true, 'row_visible_after_filter' => true,
                    'column_visible' => true]],
        ];
    }

    public function test_small_window_keeps_the_exact_cell_and_marks_sheet_edges(): void
    {
        $window = (new XlsxCellWindowBuilder)->build($this->nodes(), $this->source(), '12.4');
        self::assertSame('B2', $window['focus']['coordinate']);
        self::assertSame(1, $window['window']['startRow']);
        self::assertSame(1, $window['window']['startColumn']);
        self::assertTrue($window['window']['truncated']['top']);
        self::assertTrue($window['window']['truncated']['left']);
        self::assertCount(5, $window['window']['rows']);
        self::assertSame(12.4, $window['window']['rows'][1]['cells'][1]['rawValue']);
        self::assertFalse($window['window']['rows'][0]['cells'][0]['present']);
    }

    public function test_conflicting_anchors_for_one_coordinate_are_ambiguous(): void
    {
        $source = $this->source();
        $source['roles']['value'] = [[...$source['location'], 'id' => 'other-anchor']];
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('SOURCE_AMBIGUOUS');
        (new XlsxCellWindowBuilder)->build($this->nodes(), $source, '12.4');
    }

    public function test_invalid_coordinate_and_mismatched_projection_are_unavailable(): void
    {
        foreach (['sheet:0!XFE1048577', 'sheet:0!B0', 'sheet:0!b2'] as $path) {
            try {
                $source = $this->source();
                $source['location']['path'] = $path;
                (new XlsxCellWindowBuilder)->build($this->nodes(), $source, '12.4');
                self::fail('Invalid coordinate was accepted.');
            } catch (DomainException $failure) {
                self::assertSame('SOURCE_UNAVAILABLE', $failure->getMessage());
            }
        }
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('SOURCE_UNAVAILABLE');
        (new XlsxCellWindowBuilder)->build($this->nodes(), $this->source(), '12.5');
    }

    public function test_large_source_value_is_refused_without_truncating_it(): void
    {
        $nodes = $this->nodes();
        $nodes[1]['meta']['raw'] = str_repeat('x', 1025);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('SOURCE_TOO_COMPLEX');
        (new XlsxCellWindowBuilder)->build($nodes, $this->source(), '12.4');
    }
}
