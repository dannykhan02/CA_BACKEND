<?php

namespace Tests\Unit;

use App\Services\AI\Incremental\SourceSpanBuilder;
use Tests\TestCase;

class SourceSpanBuilderTest extends TestCase
{
    private function build(string $text, ?int $firstPage = 1): array
    {
        return app(SourceSpanBuilder::class)->build($text, $firstPage);
    }

    /** The exact original characters for a span, retrieved the way DocIntel retrieves them. */
    private function text(string $source, array $span): string
    {
        return mb_substr($source, $span['start_offset'], $span['end_offset'] - $span['start_offset']);
    }

    private function texts(string $source, array $spans): array
    {
        return array_map(fn ($span) => $this->text($source, $span), $spans);
    }

    public function test_ordinary_sentences_become_separate_spans_in_document_order(): void
    {
        $source = 'The Bank approved thirty-seven sovereign operations during the 2024 financial year. '
            .'Commitments to the private sector grew by a fifth over the same period, the fastest in a decade. '
            .'Disbursements lagged behind commitments across every regional department.';
        $spans = $this->build($source);

        self::assertGreaterThanOrEqual(3, count($spans));
        self::assertSame(['E001', 'E002', 'E003'], array_slice(array_column($spans, 'key'), 0, 3));
        self::assertSame([1, 2, 3], array_slice(array_column($spans, 'ordinal'), 0, 3));
        foreach ($spans as $span) {
            self::assertSame('sentence', $span['type']);
            self::assertStringEndsWith('.', $this->text($source, $span));
        }
        // Offsets are strictly increasing and never overlap.
        for ($index = 1; $index < count($spans); $index++) {
            self::assertGreaterThanOrEqual($spans[$index - 1]['end_offset'], $spans[$index]['start_offset']);
        }
    }

    public function test_segmentation_loses_no_source_character_except_whitespace(): void
    {
        $source = "SECTION ONE\n\nFirst sentence here. Second sentence here.\n\n"
            ."Revenue      6.4     5.9\nAssets      42.1    39.8\n\n- Item one\n- Item two\n\fPage two prose line.\n";
        $spans = $this->build($source);
        $covered = preg_replace('/\s+/u', '', implode('', $this->texts($source, $spans)));

        self::assertSame(preg_replace('/\s+/u', '', $source), $covered);
    }

    public function test_short_paragraph_stays_one_span_and_long_paragraph_is_split(): void
    {
        $short = 'Total commitments reached twelve point four billion dollars in the year under review.';
        self::assertCount(1, $this->build($short));

        $long = str_repeat('The regional department reported steady progress against its approved targets. ', 12);
        $spans = $this->build($long);
        self::assertGreaterThan(1, count($spans));
        foreach ($spans as $span) {
            self::assertLessThanOrEqual((int) config('document_intelligence.span_max_chars') + 80,
                $span['end_offset'] - $span['start_offset']);
        }
    }

    public function test_line_breaks_inside_a_paragraph_do_not_create_spans(): void
    {
        // PDF text extraction wraps a single sentence over several lines. That is exactly the
        // formatting difference that used to make a reproduced quote fail grounding.
        $wrapped = "Total commitments reached\n$12.4 billion, compared\nwith $10.1 billion in 2023.";
        $spans = $this->build($wrapped);

        self::assertCount(1, $spans);
        self::assertSame($wrapped, $this->text($wrapped, $spans[0]));
    }

    public function test_monetary_values_dates_and_abbreviations_are_never_split(): void
    {
        $source = 'The U.S. Treasury confirmed that $12.4 billion was committed. '
            .'Repayment is due on 2025-03-31. Mr. Doe signed on behalf of the agency, No. 4421, approx. 18 months later.';
        $texts = $this->texts($source, $this->build($source));

        foreach (['$12.4 billion', 'U.S. Treasury', '2025-03-31', 'Mr. Doe', 'No. 4421', 'approx. 18 months'] as $phrase) {
            self::assertTrue((bool) array_filter($texts, fn ($text) => str_contains($text, $phrase)),
                $phrase.' was split across spans');
        }
    }

    public function test_unicode_punctuation_and_non_breaking_spaces_are_preserved_exactly(): void
    {
        // A non-breaking space, an en dash, curly quotes and a narrow no-break space: all the
        // characters a model would silently normalise when asked to reproduce a quote.
        $source = "Commitments rose to \u{00A0}$12.4\u{202F}billion \u{2013} the Board\u{2019}s \u{201C}strongest year\u{201D}. "
            .'A second sentence follows the first one here.';
        $spans = $this->build($source);
        $first = $this->text($source, $spans[0]);

        self::assertStringContainsString("\u{00A0}", $first);
        self::assertStringContainsString("\u{202F}", $first);
        self::assertStringContainsString("\u{2019}", $first);
        self::assertSame(mb_substr($source, 0, mb_strlen($first)), $first);
    }

    public function test_list_items_are_one_span_each_including_wrapped_continuations(): void
    {
        $source = "- The borrower shall deliver audited financial statements\n  within one hundred and eighty days.\n"
            ."- The borrower shall maintain the agreed capital ratio.\n(a) A third marker style is also recognised.\n";
        $spans = $this->build($source);

        self::assertCount(3, $spans);
        foreach ($spans as $span) {
            self::assertSame('list_item', $span['type']);
        }
        self::assertStringContainsString('within one hundred and eighty days.', $this->text($source, $spans[0]));
    }

    public function test_table_rows_are_one_span_each_and_keep_their_label_with_their_values(): void
    {
        $source = "Item          2024      2023\nRevenue       6.4       5.9\nTotal assets  42.1      39.8\n";
        $spans = $this->build($source);

        self::assertCount(3, $spans);
        self::assertSame(['table_row', 'table_row', 'table_row'], array_column($spans, 'type'));
        self::assertStringStartsWith('Revenue', $this->text($source, $spans[1]));
        self::assertStringContainsString('5.9', $this->text($source, $spans[1]));
    }

    public function test_pipe_delimited_table_rows_are_recognised(): void
    {
        $source = "Revenue | 2024: 6.4 | 2023: 5.9\nAssets | 2024: 42.1 | 2023: 39.8\n";
        $spans = $this->build($source);

        self::assertCount(2, $spans);
        self::assertSame(['table_row', 'table_row'], array_column($spans, 'type'));
    }

    public function test_prose_mentioning_several_figures_is_not_treated_as_a_table(): void
    {
        $source = 'Commitments of 12.4, 10.1 and 9.8 billion were recorded in 2024, 2023 and 2022 respectively.';
        $spans = $this->build($source);

        self::assertCount(1, $spans);
        self::assertSame('sentence', $spans[0]['type']);
    }

    public function test_headings_keep_short_dependent_content_and_never_swallow_a_paragraph(): void
    {
        $withShortContent = "CAPITAL ADEQUACY\n\nRatio: 14.2%\n";
        $spans = $this->build($withShortContent);
        self::assertCount(1, $spans);
        self::assertSame('section', $spans[0]['type']);

        $withParagraph = "CAPITAL ADEQUACY\n\n"
            .'The ratio stood at fourteen point two per cent at the end of the reporting period, above the regulatory floor.';
        $spans = $this->build($withParagraph);
        self::assertCount(2, $spans);
        self::assertSame('heading', $spans[0]['type']);
        self::assertSame('CAPITAL ADEQUACY', $this->text($withParagraph, $spans[0]));
    }

    public function test_numbered_section_titles_are_headings_and_numbered_list_items_are_not(): void
    {
        $source = "2. FINANCIAL REVIEW\n\n2.1 Overview\n\n1. The borrower shall repay the loan in full.\n";
        $spans = $this->build($source);
        $types = array_column($spans, 'type');

        self::assertContains('list_item', $types);
        self::assertNotContains('list_item', [$types[0]]);
        self::assertStringContainsString('FINANCIAL REVIEW', $this->text($source, $spans[0]));
    }

    public function test_page_boundaries_are_preserved_and_numbered_from_the_first_page(): void
    {
        $source = "First page sentence that is long enough to stand alone.\fSecond page sentence that is also long enough.";
        $spans = $this->build($source);

        self::assertCount(2, $spans);
        self::assertSame([1, 2], array_column($spans, 'page'));
        // No span may contain a page separator.
        foreach ($spans as $span) {
            self::assertStringNotContainsString("\f", $this->text($source, $span));
        }
    }

    public function test_a_span_never_merges_across_a_page_break(): void
    {
        // Both fragments are short enough to be grouped, but they are on different pages.
        $source = "Short tail.\fShort head.";
        $spans = $this->build($source);

        self::assertCount(2, $spans);
        self::assertSame([1, 2], array_column($spans, 'page'));
    }

    public function test_pages_are_null_when_the_page_map_is_not_known(): void
    {
        $source = "A sentence on an unknown page which is long enough.\fAnother one here that is also long enough.";
        foreach ($this->build($source, null) as $span) {
            self::assertNull($span['page']);
        }
    }

    public function test_ocr_like_text_is_segmented_without_being_corrected(): void
    {
        // Noisy OCR: stray spacing, a mis-recognised character and inconsistent casing. The exact
        // output the model saw must come back unchanged - span IDs do not fix OCR accuracy.
        $source = "T0tal c0mmitments  reached $12.4 billi0n.\nTbe Bank  appr0ved 37 0perations.\n";
        $spans = $this->build($source);
        $covered = implode('', $this->texts($source, $spans));

        self::assertStringContainsString('T0tal c0mmitments  reached', $covered);
        self::assertStringContainsString('Tbe Bank  appr0ved', $covered);
        self::assertSame(preg_replace('/\s+/u', '', $source), preg_replace('/\s+/u', '', $covered));
    }

    public function test_ids_are_stable_and_unique_across_repeated_segmentation(): void
    {
        $source = str_repeat("A sentence of quite ordinary length appears here. Another follows it immediately.\n\n", 20);
        $first = $this->build($source);
        $second = $this->build($source);

        self::assertSame($first, $second);
        self::assertSame(count($first), count(array_unique(array_column($first, 'key'))));
    }

    public function test_id_width_grows_with_the_span_count_without_colliding(): void
    {
        $source = str_repeat("A sentence that comfortably stands on its own right here.\n\n", 1100);
        $spans = $this->build($source);

        self::assertGreaterThan(999, count($spans));
        self::assertSame(count($spans), count(array_unique(array_column($spans, 'key'))));
        self::assertSame('E0001', $spans[0]['key']);
    }

    public function test_whitespace_only_text_produces_no_spans(): void
    {
        self::assertSame([], $this->build("   \n\n \f \n"));
        self::assertSame([], $this->build(''));
    }

    public function test_a_single_overlong_sentence_is_split_at_safe_punctuation(): void
    {
        $source = 'The agreement covers '.str_repeat('one named counterparty and its affiliates, ', 60).'and nothing else';
        $spans = $this->build($source);

        self::assertGreaterThan(1, count($spans));
        foreach ($spans as $span) {
            self::assertLessThanOrEqual((int) config('document_intelligence.span_hard_max_chars'),
                $span['end_offset'] - $span['start_offset']);
        }
        // No split lands inside a word.
        foreach ($this->texts($source, $spans) as $text) {
            self::assertMatchesRegularExpression('/^\S/u', $text);
        }
        self::assertSame(preg_replace('/\s+/u', '', $source),
            preg_replace('/\s+/u', '', implode('', $this->texts($source, $spans))));
    }
}
