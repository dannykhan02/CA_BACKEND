<?php

namespace Tests\Unit\Services;

use App\Services\Extraction\PdfTableStructureProjector;
use App\Services\Extraction\StructuredDocument;
use App\Services\Intelligence\PdfCaptionScale;
use App\Services\Intelligence\PeriodParser;
use Tests\TestCase;

class PdfPhase5GeneralizationTest extends TestCase
{
    public function test_non_month_end_duration_retains_printed_date_and_does_not_become_calendar_quarter(): void
    {
        $parser = app(PeriodParser::class);
        $quarter = $parser->parse('Three Months Ended March 29, 2025');
        $sixMonths = $parser->parse('Six Months Ended March 29, 2025');
        self::assertSame('3M ended 2025-03-29', $quarter->label);
        self::assertSame('3_months', $quarter->granularity);
        self::assertSame('6M ended 2025-03-29', $sixMonths->label);
        self::assertFalse($quarter->comparableWith($sixMonths));
        self::assertSame('Q3 2026', $parser->parse('Three Months Ended September 30, 2026')->label);
        self::assertNull($parser->parse('Three Months Ended February 30, 2025'));
    }

    public function test_mixed_scale_caption_excludes_share_count_and_percentage_rows(): void
    {
        $caption = '(In millions, except number of shares, which are reflected in thousands, and per-share amounts)';
        self::assertSame('million', PdfCaptionScale::forRow($caption, 'Operating income'));
        foreach (['Basic', 'Diluted', 'Shares outstanding', 'Operating margin'] as $row) {
            self::assertNull(PdfCaptionScale::forRow($caption, $row));
        }
        self::assertNull(PdfCaptionScale::forRow('(In millions and thousands)', 'Operating income'));
    }

    public function test_split_exact_text_projects_date_columns_without_joining_missing_values(): void
    {
        $text = "Example Inc.\n(In millions, except number of shares, which are reflected in thousands, and per-share amounts)\n"
            ."Three Months Ended Six Months Ended\n"
            ."March 29,\n2025\nMarch 30,\n2024\nMarch 29,\n2025\nMarch 30,\n2024\n"
            ."Operating income $ 29,589 $ 27,900 $ 72,421 $ 68,273\n"
            .'Net income $ 24,780 $ 23,636 $ 61,110 $ 57,552';
        $page = ['id' => 'page:1', 'type' => 'page', 'path' => 'page:1', 'page' => 1,
            'start' => 0, 'end' => mb_strlen($text), 'text' => $text];
        $source = new StructuredDocument('PDF', 'smalot-page.v2', $text, [$page], []);
        $scopes = [
            ['text' => 'Three Months Ended', 'bbox' => [85, 20, 215, 30]],
            ['text' => 'Six Months Ended', 'bbox' => [285, 20, 415, 30]],
        ];
        $headers = [['text' => '', 'bbox' => null]];
        $periods = [];
        foreach ([['March 29,', '2025', 100, 0], ['March 30,', '2024', 200, 0],
            ['March 29,', '2025', 300, 1], ['March 30,', '2024', 400, 1]] as $column => [$date, $year, $x, $scope]) {
            $datePart = ['text' => $date, 'bbox' => [$x - 15, 35, $x + 15, 45]];
            $yearPart = ['text' => $year, 'bbox' => [$x - 10, 50, $x + 10, 60]];
            $headers[] = [...$yearPart, 'source_path' => [$scopes[$scope], $datePart, $yearPart]];
            $periods[$column + 1] = app(PeriodParser::class)
                ->parse($scopes[$scope]['text'].' '.$date.' '.$year)->label;
        }
        $rows = [];
        foreach ([['Operating income', '29,589', '27,900', '72,421', '68,273'],
            ['Net income', '24,780', '23,636', '61,110', '57,552']] as $row => $values) {
            $cells = [];
            foreach ($values as $column => $value) {
                $x = $column === 0 ? 10 : $column * 100;
                $cells[] = ['text' => $value, 'bbox' => [$x, 70 + $row * 20,
                    $x + 60, 80 + $row * 20]];
            }
            $rows[] = $cells;
        }
        $table = ['page' => 1, 'page_width' => 500.0, 'page_height' => 700.0,
            'bbox' => [10, 20, 460, 100], 'header' => $headers, 'periods' => $periods,
            'unit_source' => '(In millions, except number of shares, which are reflected in thousands, and per-share amounts)',
            'entity_source' => 'Example Inc.', 'rows' => $rows,
            'status' => 'layout_candidate_only'];
        $projected = app(PdfTableStructureProjector::class)->augment($source, [$table]);
        self::assertSame(1, $projected->capabilities['projected_tables']);
        self::assertSame('29,589', $projected->node('page:1/table[1]/row[1]/cell[1]')['text']);
        self::assertSame('27,900', $projected->node('page:1/table[1]/row[1]/cell[2]')['text']);
        self::assertSame('57,552', $projected->node('page:1/table[1]/row[2]/cell[4]')['text']);
        self::assertCount(3, $projected->node('page:1/table[1]/row[1]/cell[1]')['meta']['header_path_ids']);

        $table['rows'][0][2]['text'] = '99,999';
        self::assertSame(0, app(PdfTableStructureProjector::class)
            ->augment($source, [$table])->capabilities['projected_tables']);
    }
}
