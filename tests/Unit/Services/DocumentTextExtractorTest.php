<?php

namespace Tests\Unit\Services;

use App\Services\DocumentTextExtractor;
use PHPUnit\Framework\TestCase;

class DocumentTextExtractorTest extends TestCase
{
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
            $this->expectExceptionMessage('Invalid DOCX');
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
}
