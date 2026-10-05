<?php

namespace App\Services;

use Smalot\PdfParser\Parser as PdfParser;

/**
 * Shared text-extraction logic used by ExtractDocumentTextJob (initial
 * pipeline run) and GenerateInsightsJob (fallback re-extraction when the
 * 2hr extracted-text cache has expired before a manual reprocess).
 * Centralizing this avoids two classes drifting out of sync, and avoids
 * reaching into another class's private methods via reflection.
 */
class DocumentTextExtractor
{
    private array $pdfPageCounts = [];

    public function extractPdfText(string $path): string
    {
        $parser = new PdfParser;

        $pages = $parser->parseFile($path)->getPages();
        $this->pdfPageCounts[$path] = count($pages);

        $text = implode(
            "\f",
            array_map(
                fn ($page) => $page->getText(),
                $pages
            )
        );

        return $this->sanitizeUtf8($text);
    }

    /**
     * Make extracted document text safe for PostgreSQL UTF-8 storage and
     * downstream processing.
     *
     * PDF parsers can return mostly valid UTF-8 containing isolated legacy
     * bytes or control characters. PostgreSQL correctly rejects malformed
     * UTF-8, so clean it at the extraction boundary.
     *
     * Structural characters intentionally preserved:
     * - tab:             \x09
     * - newline:         \x0A
     * - form feed:       \x0C
     * - carriage return: \x0D
     */
    public function sanitizeUtf8(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            $clean = @iconv(
                'UTF-8',
                'UTF-8//IGNORE',
                $text
            );

            if ($clean !== false) {
                $text = $clean;
            } else {
                $text = mb_scrub(
                    $text,
                    'UTF-8'
                );
            }
        }

        /*
         * PostgreSQL text values cannot contain NUL bytes.
         */
        $text = str_replace(
            "\0",
            '',
            $text
        );

        /*
         * Remove non-structural ASCII control characters sometimes emitted
         * by PDF text layers.
         */
        $clean = preg_replace(
            '/[\x01-\x08\x0B\x0E-\x1F\x7F]/u',
            '',
            $text
        );

        return $clean ?? $text;
    }

    /**
     * Page count has never been populated anywhere in the pipeline —
     * `pages` is set to 0 at upload (DocumentUploadController) and never
     * touched again. Scoped to PDF only, where smalot/pdfparser already
     * gives a reliable answer via getPages() — the same accessor already
     * used for embedded-image detection (PdfImageDetector).
     */
    public function countPdfPages(string $path): int
    {
        if (isset($this->pdfPageCounts[$path])) {
            return $this->pdfPageCounts[$path];
        }

        $parser = new PdfParser;

        return count(
            $parser->parseFile($path)->getPages()
        );
    }

    /**
     * Read the WordprocessingML text directly. Image decoding is unrelated
     * to text extraction and PhpWord rejects otherwise valid EMF drawings.
     * Paragraph, tab and table-cell boundaries remain explicit for analysis.
     */
    public function extractDocxText(string $path): string
    {
        $zip = new \ZipArchive;

        if (
            $zip->open(
                $path,
                \ZipArchive::CHECKCONS
            ) !== true
        ) {
            throw new \RuntimeException(
                'Invalid DOCX: could not open ZIP archive.'
            );
        }

        try {
            $size = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $size += $zip->statIndex($i)['size'];

                if ($size > 200 * 1024 * 1024) {
                    throw new \RuntimeException(
                        'Document exceeds safe decompressed size limits.'
                    );
                }
            }

            $xml = $zip->getFromName(
                'word/document.xml'
            );

            if (
                $xml === false
                || $zip->locateName(
                    '[Content_Types].xml'
                ) === false
            ) {
                throw new \RuntimeException(
                    'Invalid DOCX: missing document XML or content types.'
                );
            }
        } finally {
            $zip->close();
        }

        $previous = libxml_use_internal_errors(
            true
        );

        try {
            $dom = new \DOMDocument;

            if (
                ! $dom->loadXML(
                    $xml,
                    LIBXML_NONET
                )
                || $dom->doctype !== null
            ) {
                throw new \RuntimeException(
                    'Invalid DOCX: malformed or unsafe document XML.'
                );
            }

            $namespace =
                $dom->documentElement?->namespaceURI;

            if (
                $dom->documentElement?->localName
                    !== 'document'
                || ! in_array(
                    $namespace,
                    [
                        'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
                        'http://purl.oclc.org/ooxml/wordprocessingml/main',
                    ],
                    true
                )
            ) {
                throw new \RuntimeException(
                    'Invalid DOCX: expected WordprocessingML document.'
                );
            }

            $xpath = new \DOMXPath($dom);

            $xpath->registerNamespace(
                'w',
                $namespace
            );

            $body = $xpath
                ->query('/w:document/w:body')
                ->item(0);

            if (! $body) {
                throw new \RuntimeException(
                    'Invalid DOCX: missing document body.'
                );
            }

            $text = $this->extractWordXml(
                $body,
                $namespace
            );

            return $this->sanitizeUtf8(
                $text
            );
        } finally {
            libxml_clear_errors();

            libxml_use_internal_errors(
                $previous
            );
        }
    }

    private function extractWordXml(
        \DOMNode $node,
        string $namespace
    ): string {
        if ($node->namespaceURI === $namespace) {
            if (
                in_array(
                    $node->localName,
                    ['del', 'instrText'],
                    true
                )
            ) {
                return '';
            }

            if ($node->localName === 't') {
                return $node->textContent;
            }

            if ($node->localName === 'tab') {
                return "\t";
            }

            if (
                in_array(
                    $node->localName,
                    ['br', 'cr'],
                    true
                )
            ) {
                return "\n";
            }
        }

        $text = '';

        foreach ($node->childNodes as $child) {
            $text .= $this->extractWordXml(
                $child,
                $namespace
            );
        }

        if ($node->namespaceURI === $namespace) {
            return match ($node->localName) {
                'p', 'tr' =>
                    rtrim(
                        $text,
                        "\n |"
                    )."\n",

                'tc' =>
                    trim($text).' | ',

                default =>
                    $text,
            };
        }

        return $text;
    }
}