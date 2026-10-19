<?php

namespace Tests\Unit\Services;

use App\Services\DocumentTextExtractor;
use App\Services\Extraction\StructuredDocument;
use Tests\TestCase;

/**
 * What the DOCX extraction really leaves out — the evidence behind the
 * `excluded` list the docx-window endpoint now returns.
 *
 * The list has to be measured, not assumed, because over-claiming is its own
 * dishonesty: telling a reader that something is missing from their document
 * when it is in fact being shown is the same class of error as drawing a box
 * in the wrong place. Each case below is one entry in that list, or one
 * entry deliberately kept out of it.
 *
 * No `Storage::fake('documents')` here: the extractor is handed a temp path
 * directly and never touches the documents disk.
 */
class DocxExcludedContentTest extends TestCase
{
    /** A DOCX carrying one of every part this question is about. */
    private function docx(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docx_excluded_');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');

        // Never opened by the extractor, so none of these words may appear.
        $zip->addFromString('word/header1.xml',
            '<w:hdr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:p><w:r><w:t>HEADERWORD</w:t></w:r></w:p></w:hdr>');
        $zip->addFromString('word/footer1.xml',
            '<w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:p><w:r><w:t>FOOTERWORD</w:t></w:r></w:p></w:ftr>');
        $zip->addFromString('word/comments.xml',
            '<w:comments xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:comment w:id="1"><w:p><w:r><w:t>COMMENTWORD</w:t></w:r></w:p></w:comment></w:comments>');
        $zip->addFromString('word/endnotes.xml',
            '<w:endnotes xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:endnote w:id="1"><w:p><w:r><w:t>ENDNOTEWORD</w:t></w:r></w:p></w:endnote></w:endnotes>');

        // Read, and kept as its own node rather than as body text.
        $zip->addFromString('word/footnotes.xml',
            '<w:footnotes xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:footnote w:id="0"><w:p><w:r><w:t>SEPARATORWORD</w:t></w:r></w:p></w:footnote>'
            .'<w:footnote w:id="2"><w:p><w:r><w:t>FOOTNOTEWORD</w:t></w:r></w:p></w:footnote></w:footnotes>');

        $xml = '<w:document '
            .'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
            .'xmlns:v="urn:schemas-microsoft-com:vml"><w:body>'
            // A text box lives inside a body run.
            .'<w:p><w:r><w:t>Body opening.</w:t></w:r><w:r><w:pict><v:shape><v:textbox>'
            .'<w:txbxContent><w:p><w:r><w:t>TEXTBOXWORD</w:t></w:r></w:p></w:txbxContent>'
            .'</v:textbox></v:shape></w:pict></w:r></w:p>'
            // A tracked deletion beside kept text, and a moveFrom.
            .'<w:p><w:r><w:t>Kept sentence.</w:t></w:r>'
            .'<w:del><w:r><w:delText>DELETEDWORD</w:delText></w:r></w:del>'
            .'<w:moveFrom><w:r><w:t>MOVEDFROMWORD</w:t></w:r></w:moveFrom></w:p>'
            // A field code beside its visible result.
            .'<w:p><w:r><w:instrText>INSTRWORD</w:instrText></w:r><w:r><w:t>Visible result.</w:t></w:r></w:p>'
            // An image, whose only text is alt text in another namespace.
            .'<w:p><w:r><w:drawing><wp:inline xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
            .'<wp:docPr id="1" name="Picture 1" descr="IMAGEALTWORD"/></wp:inline></w:drawing></w:r></w:p>'
            // A numbered list item: the marker itself is never stored.
            .'<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="3"/></w:numPr></w:pPr>'
            .'<w:r><w:t>First condition.</w:t></w:r></w:p>'
            // A footnote reference in the body.
            .'<w:p><w:r><w:t>Carries a note.</w:t></w:r><w:r><w:footnoteReference w:id="2"/></w:r></w:p>'
            .'</w:body></w:document>';
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        return $path;
    }

    private function structure(): StructuredDocument
    {
        $path = $this->docx();
        try {
            return app(DocumentTextExtractor::class)->extractDocxStructured($path);
        } finally {
            unlink($path);
        }
    }

    public function test_text_box_words_are_extracted_so_they_must_not_be_called_excluded(): void
    {
        // The surprising one, and the reason `text_box_text` is NOT in the
        // excluded list: `extractWordXml` returns the text of every
        // descendant `w:t`, and a text box's paragraphs are descendants of a
        // body run, so its words land in the containing paragraph.
        $structure = $this->structure();
        self::assertStringContainsString('TEXTBOXWORD', $structure->text);

        $paragraph = null;
        foreach ($structure->nodes as $node) {
            if (($node['type'] ?? null) === 'paragraph' && str_contains($node['text'] ?? '', 'Body opening')) {
                $paragraph = $node;
            }
        }
        self::assertNotNull($paragraph, 'the opening body paragraph is projected');
        self::assertStringContainsString('TEXTBOXWORD', $paragraph['text']);
    }

    public function test_headers_footers_comments_and_endnotes_are_never_read(): void
    {
        // Only word/document.xml and word/footnotes.xml are opened, so these
        // four parts cannot contribute a single character.
        $structure = $this->structure();
        foreach (['HEADERWORD', 'FOOTERWORD', 'COMMENTWORD', 'ENDNOTEWORD'] as $word) {
            self::assertStringNotContainsString($word, $structure->text);
        }
        self::assertSame('unavailable', $structure->capabilities['headers_footers']);
    }

    public function test_tracked_deletions_moved_from_text_and_field_codes_are_dropped(): void
    {
        $structure = $this->structure();
        self::assertStringContainsString('Kept sentence.', $structure->text);
        self::assertStringNotContainsString('DELETEDWORD', $structure->text);
        self::assertStringNotContainsString('MOVEDFROMWORD', $structure->text);
        self::assertStringContainsString('Visible result.', $structure->text);
        self::assertStringNotContainsString('INSTRWORD', $structure->text);
        self::assertSame('deleted_text_excluded', $structure->capabilities['tracked_changes']);
    }

    public function test_an_image_contributes_no_text_not_even_its_alt_text(): void
    {
        self::assertStringNotContainsString('IMAGEALTWORD', $this->structure()->text);
    }

    public function test_list_markers_are_not_stored_only_the_list_identity(): void
    {
        $structure = $this->structure();
        self::assertStringContainsString('without_markers', $structure->capabilities['lists']);
        $item = null;
        foreach ($structure->nodes as $node) {
            if (str_contains($node['text'] ?? '', 'First condition.')) {
                $item = $node;
            }
        }
        self::assertNotNull($item);
        // The number the reader saw in Word is absent; only num_id and level.
        self::assertSame('First condition.', trim($item['text']));
        self::assertSame('3', $item['meta']['list_id']);
    }

    public function test_footnote_text_is_its_own_node_and_never_a_body_block(): void
    {
        // Extracted — so the document's own words are not lost — but outside
        // `body`, which is the only subtree the window walks. "Not shown here"
        // is therefore true of it, which is why `footnote_text` is listed.
        $structure = $this->structure();
        $footnotes = array_values(array_filter($structure->nodes,
            static fn (array $node): bool => ($node['type'] ?? null) === 'footnote'));
        self::assertCount(1, $footnotes, 'the separator footnote (id 0) is not a source note');
        self::assertSame('footnote:2', $footnotes[0]['id']);
        self::assertSame('FOOTNOTEWORD', $footnotes[0]['text']);
        self::assertArrayNotHasKey('parent', $footnotes[0]);
        self::assertSame('referenced_source_nodes', $structure->capabilities['footnotes']);
    }
}
