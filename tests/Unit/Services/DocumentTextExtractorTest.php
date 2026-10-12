<?php

namespace Tests\Unit\Services;

use App\Services\DocumentTextExtractor;
use App\Services\Intelligence\StructuredProvenanceProducer;
use Tests\TestCase;

class DocumentTextExtractorTest extends TestCase
{
    public function test_pdf_utf8_sanitation_preserves_unicode_and_page_structure(): void
    {
        $valid = "Renée — € £ ¥\tNairobi\nJosé\f東京\r\n";
        $extractor = new DocumentTextExtractor;
        self::assertSame($valid, $extractor->sanitizeUtf8($valid));
        $clean = $extractor->sanitizeUtf8($valid."\x96\0\x01");
        self::assertTrue(mb_check_encoding($clean, 'UTF-8'));
        self::assertSame($valid, $clean);
    }

    public function test_embedded_emf_does_not_prevent_paragraph_and_table_extraction(): void
    {
        $path = dirname(__DIR__, 2).'/Fixtures/embedded-emf.docx';
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));
        $this->assertSame(' EMF', substr($zip->getFromName('word/media/image1.emf'), 40, 4));
        $zip->close();
        $this->assertSame("Audit regression report\n\nAchievement | 85%\n",
            (new DocumentTextExtractor)->extractDocxText($path));
    }

    public function test_truncated_zip_is_rejected_as_invalid_docx(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bad_docx_');
        try {
            file_put_contents($path, substr(file_get_contents(dirname(__DIR__, 2).'/Fixtures/embedded-emf.docx'), 0, 100));
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Invalid document archive');
            (new DocumentTextExtractor)->extractDocxText($path);
        } finally {
            unlink($path);
        }
    }

    public function test_malformed_xml_and_external_entities_are_rejected(): void
    {
        foreach (['<broken>', '<!DOCTYPE x [<!ENTITY x SYSTEM "file:///etc/hosts">]><x>&x;</x>'] as $xml) {
            $path = tempnam(sys_get_temp_dir(), 'bad_xml_');
            try {
                $zip = new \ZipArchive;
                $zip->open($path, \ZipArchive::OVERWRITE);
                $zip->addFromString('[Content_Types].xml', '<Types/>');
                $zip->addFromString('word/document.xml', $xml);
                $zip->close();
                try {
                    (new DocumentTextExtractor)->extractDocxText($path);
                    $this->fail('Invalid XML was accepted');
                } catch (\RuntimeException $e) {
                    $this->assertStringContainsString('Invalid DOCX', $e->getMessage());
                }
            } finally {
                unlink($path);
            }
        }
    }

    public function test_native_pdf_preserves_page_boundaries_and_reuses_page_count(): void
    {
        $extractor = new DocumentTextExtractor;
        $path = dirname(__DIR__, 2).'/Fixtures/test_5page.pdf';
        $text = $extractor->extractPdfText($path);
        self::assertSame(4, substr_count($text, "\f"));
        self::assertSame(5, $extractor->countPdfPages($path));
        self::assertStringContainsString('Synthetic benchmark page 5', explode("\f", $text)[4]);
    }

    public function test_docx_footnote_reference_keeps_body_text_and_explicit_note_edge(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'footnote_docx_');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('word/document.xml', <<<'XML'
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Revenue 12.4</w:t></w:r><w:r><w:footnoteReference w:id="1"/></w:r></w:p></w:body></w:document>
XML);
        $zip->addFromString('word/footnotes.xml', <<<'XML'
<w:footnotes xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:footnote w:id="1"><w:p><w:r><w:t>Restated figure.</w:t></w:r></w:p></w:footnote></w:footnotes>
XML);
        $zip->close();
        try {
            $source = (new DocumentTextExtractor)->extractDocxStructured($path);
            self::assertSame("Revenue 12.4\n", $source->text);
            self::assertSame('Restated figure.', $source->node('footnote:1')['text']);
            self::assertSame(['1'], $source->node('body/p[1]')['meta']['footnote_ids']);
            [, $edges] = app(StructuredProvenanceProducer::class)->build($source, []);
            self::assertContains('footnote', array_column($edges, 'type'));
        } finally {
            unlink($path);
        }
    }

    public function test_docx_heading_style_sets_bounded_section_ownership(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'heading_docx_');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('word/document.xml', <<<'XML'
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>
<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Finance</w:t></w:r></w:p>
<w:p><w:r><w:t>Revenue 12.4</w:t></w:r></w:p>
<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Operations</w:t></w:r></w:p>
<w:p><w:r><w:t>Employees 30</w:t></w:r></w:p>
</w:body></w:document>
XML);
        $zip->close();
        try {
            $source = (new DocumentTextExtractor)->extractDocxStructured($path);
            self::assertSame('section_heading', $source->node('body/p[1]')['type']);
            self::assertSame('body/p[1]', $source->node('body/p[2]')['meta']['section_heading_id']);
            self::assertSame('body/p[3]', $source->node('body/p[4]')['meta']['section_heading_id']);
            [, $edges] = app(StructuredProvenanceProducer::class)->build($source, []);
            self::assertSame(2, count(array_filter($edges,
                static fn (array $edge): bool => $edge['type'] === 'section_parent'
                    && $edge['from'] !== $edge['to'])));
        } finally {
            unlink($path);
        }
    }

    public function test_pdf_page_limit_and_malformed_file_fail_before_parser(): void
    {
        $path = dirname(__DIR__, 2).'/Fixtures/test_5page.pdf';
        config(['document_processing.pdf_max_pages' => 4]);
        try {
            (new DocumentTextExtractor)->extractPdfText($path);
            $this->fail('Over-limit PDF must be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertSame('PDF page limit exceeded.', $e->getMessage());
        }

        config(['document_processing.pdf_max_pages' => 1000]);
        $bad = tempnam(sys_get_temp_dir(), 'bad_pdf_');
        try {
            file_put_contents($bad, 'invalid PDF');
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('PDF is malformed or encrypted');
            (new DocumentTextExtractor)->extractPdfText($bad);
        } finally {
            unlink($bad);
        }
    }
}
