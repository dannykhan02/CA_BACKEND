<?php

namespace App\Services;

use App\Services\Extraction\ArchivePreflight;
use App\Services\Extraction\StructuredDocument;
use Smalot\PdfParser\Parser as PdfParser;
use Symfony\Component\Process\Process;

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

    private ?StructuredDocument $lastPdfStructure = null;

    public function extractPdfText(string $path): string
    {
        $this->lastPdfStructure = $this->extractPdfStructured($path);

        return $this->lastPdfStructure->text;
    }

    public function pdfStructuredResult(): ?StructuredDocument
    {
        return $this->lastPdfStructure;
    }

    public function extractPdfStructured(string $path): StructuredDocument
    {
        $this->preflightPdf($path);
        $parser = new PdfParser;

        $pages = $parser->parseFile($path)->getPages();
        $this->pdfPageCounts[$path] = count($pages);

        $parts = [];
        $nodes = [];
        $emptyPages = [];
        $offset = 0;
        foreach ($pages as $index => $page) {
            if ($index > 0) {
                $offset++;
            }
            $pageText = $this->sanitizeUtf8($page->getText());
            if (trim($pageText) === '') {
                $emptyPages[] = $index + 1;
            }
            $parts[] = $pageText;
            $node = ['id' => 'page:'.($index + 1), 'type' => 'page', 'page' => $index + 1,
                'path' => 'page:'.($index + 1), 'meta' => ['modality' => 'embedded_pdf_text',
                    'reading_order' => 'parser_order_unverified']];
            if ($pageText !== '') {
                $node += ['start' => $offset, 'end' => $offset + mb_strlen($pageText), 'text' => $pageText];
            }
            $nodes[] = $node;
            $offset += mb_strlen($pageText);
        }
        $text = implode("\f", $parts);

        return new StructuredDocument('PDF', 'smalot-page.v2', $text, $nodes,
            ['pages' => 'complete', 'text' => 'parser_text', 'blocks' => 'unavailable',
                'geometry' => 'unavailable', 'tables' => 'unavailable', 'visibility' => 'unverified',
                'reading_order' => 'parser_order_unverified', 'empty_text_pages' => $emptyPages,
                'hybrid_ocr' => $emptyPages === [] ? 'not_needed_for_empty_pages' : 'not_run']);
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

        $this->preflightPdf($path);

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
        app(ArchivePreflight::class)->check($path);
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

    /** Preserve Word body paths while keeping the established text serializer unchanged. */
    public function extractDocxStructured(string $path): StructuredDocument
    {
        $text = $this->extractDocxText($path);
        $zip = new \ZipArchive;
        if ($zip->open($path, \ZipArchive::CHECKCONS) !== true) {
            throw new \RuntimeException('Invalid DOCX archive.');
        }
        try {
            $xml = $zip->getFromName('word/document.xml');
            $footnotesXml = $zip->getFromName('word/footnotes.xml');
        } finally {
            $zip->close();
        }
        $dom = new \DOMDocument;
        if (! is_string($xml) || ! $dom->loadXML($xml, LIBXML_NONET) || $dom->doctype !== null) {
            throw new \RuntimeException('Invalid DOCX: unsafe document XML.');
        }
        $namespace = $dom->documentElement->namespaceURI;
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', $namespace);
        $body = $xpath->query('/w:document/w:body')->item(0);
        if (! $body) {
            throw new \RuntimeException('Invalid DOCX: missing document body.');
        }
        $nodes = [['id' => 'body', 'type' => 'document', 'path' => 'body']];
        $footnotes = [];
        if (is_string($footnotesXml)) {
            $footnoteDom = new \DOMDocument;
            if ($footnoteDom->loadXML($footnotesXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)
                && $footnoteDom->doctype === null
                && $footnoteDom->documentElement?->localName === 'footnotes') {
                $footnoteXpath = new \DOMXPath($footnoteDom);
                $footnoteXpath->registerNamespace('w', $namespace);
                foreach ($footnoteXpath->query('/w:footnotes/w:footnote') as $footnote) {
                    $id = $footnote instanceof \DOMElement ? $footnote->getAttributeNS($namespace, 'id') : '';
                    if (! preg_match('/^[1-9][0-9]{0,9}$/D', $id)) {
                        continue; // Word's separator and continuation separator are not source notes.
                    }
                    $noteText = trim($this->extractWordXml($footnote, $namespace));
                    if ($noteText !== '') {
                        $footnotes[$id] = ['id' => 'footnote:'.$id, 'type' => 'footnote',
                            'path' => 'word/footnotes.xml#'.$id, 'text' => $noteText];
                    }
                    if (count($footnotes) > 10000) {
                        throw new \RuntimeException('DOCX footnote limit exceeded.');
                    }
                }
            }
        }
        $cursor = 0;
        $walk = function (\DOMNode $container, string $parent, int $depth) use (&$walk, &$nodes, &$cursor,
            $text, $namespace, $xpath): void {
            if ($depth > 12) {
                throw new \RuntimeException('DOCX nested structure limit exceeded.');
            }
            $ordinals = [];
            $gridColumn = 1;
            foreach ($container->childNodes as $child) {
                if ($child->namespaceURI !== $namespace || ! in_array($child->localName,
                    ['p', 'tbl', 'tr', 'tc'], true)) {
                    continue;
                }
                $type = $child->localName;
                $ordinals[$type] = ($ordinals[$type] ?? 0) + 1;
                $id = $parent.'/'.$type.'['.$ordinals[$type].']';
                $node = ['id' => $id, 'parent' => $parent, 'path' => $id,
                    'type' => match ($type) {
                        'p' => 'paragraph', 'tbl' => 'table', 'tr' => 'row', 'tc' => 'cell'
                    }];
                if ($type === 'p') {
                    $style = $xpath->query('./w:pPr/w:pStyle', $child)->item(0);
                    $node['meta'] = ['style' => $style instanceof \DOMElement
                        ? $style->getAttributeNS($namespace, 'val') : null];
                    // Explicit numbering ownership only. Style names alone do
                    // not prove a list, and we do not simulate Word numbering.
                    $numId = $xpath->query('./w:pPr/w:numPr/w:numId', $child)->item(0);
                    $level = $xpath->query('./w:pPr/w:numPr/w:ilvl', $child)->item(0);
                    $number = $numId instanceof \DOMElement ? $numId->getAttributeNS($namespace, 'val') : '';
                    $indent = $level instanceof \DOMElement ? $level->getAttributeNS($namespace, 'val') : '0';
                    if (preg_match('/^[1-9][0-9]{0,9}$/D', $number) && preg_match('/^[0-8]$/D', $indent)) {
                        $node['meta']['list_id'] = $number;
                        $node['meta']['list_level'] = (int) $indent;
                    }
                    $references = [];
                    foreach ($xpath->query('.//w:footnoteReference', $child) as $reference) {
                        if ($reference instanceof \DOMElement) {
                            $references[] = $reference->getAttributeNS($namespace, 'id');
                        }
                    }
                    $node['meta']['footnote_ids'] = array_values(array_unique($references));
                    $segment = rtrim($this->extractWordXml($child, $namespace), "\n |");
                } elseif ($type === 'tc') {
                    $span = $xpath->query('./w:tcPr/w:gridSpan', $child)->item(0);
                    $merge = $xpath->query('./w:tcPr/w:vMerge', $child)->item(0);
                    $node['meta'] = ['grid_span' => $span instanceof \DOMElement
                        ? (int) $span->getAttributeNS($namespace, 'val') : 1,
                        'vertical_merge' => $merge instanceof \DOMElement
                            ? ($merge->getAttributeNS($namespace, 'val') ?: 'continue') : null];
                    $node['meta']['grid_column'] = $gridColumn;
                    $gridColumn += max(1, $node['meta']['grid_span']);
                    $serializedCell = $this->extractWordXml($child, $namespace);
                    $segment = trim(str_ends_with($serializedCell, ' | ')
                        ? substr($serializedCell, 0, -3) : $serializedCell);
                } else {
                    if ($type === 'tr') {
                        $header = $xpath->query('./w:trPr/w:tblHeader', $child)->item(0);
                        $headerValue = $header instanceof \DOMElement
                            ? $header->getAttributeNS($namespace, 'val') : null;
                        $node['meta'] = ['explicit_header' => $header instanceof \DOMElement
                            && ! in_array($headerValue, ['0', 'false', 'off'], true)];
                    }
                    $segment = '';
                }
                if ($segment !== '') {
                    $at = mb_strpos($text, $segment, $cursor);
                    if ($at !== false) {
                        $node += ['start' => $at, 'end' => $at + mb_strlen($segment), 'text' => $segment];
                    }
                }
                $nodes[] = $node;
                if (count($nodes) > 100000) {
                    throw new \RuntimeException('Structured node limit exceeded.');
                }
                if ($type !== 'p') {
                    $walk($child, $id, $depth + 1);
                }
                if (isset($node['end'])) {
                    $cursor = max($cursor, $node['end']);
                }
            }
        };
        $walk($body, 'body', 0);
        $headingStack = [];
        foreach ($nodes as &$node) {
            if (($node['parent'] ?? null) !== 'body') {
                continue;
            }
            $style = $node['meta']['style'] ?? null;
            if ($node['type'] === 'paragraph' && is_string($style)
                && preg_match('/^Heading([1-6])$/iD', $style, $match)) {
                $level = (int) $match[1];
                foreach (array_keys($headingStack) as $stackLevel) {
                    if ($stackLevel >= $level) {
                        unset($headingStack[$stackLevel]);
                    }
                }
                if ($headingStack !== []) {
                    $node['meta']['section_heading_id'] = end($headingStack);
                }
                $node['type'] = 'section_heading';
                $node['meta']['heading_level'] = $level;
                $headingStack[$level] = $node['id'];
            } elseif ($headingStack !== []) {
                $node['meta']['section_heading_id'] = end($headingStack);
            }
        }
        unset($node);
        array_push($nodes, ...array_values($footnotes));

        return new StructuredDocument('DOCX', 'wordprocessingml-body.v5', $text, $nodes,
            ['text' => 'complete', 'body_order' => 'complete', 'tables' => 'native_paths',
                'merged_cells' => 'grid_span_and_v_merge', 'headings' => 'heading_1_to_6_body_sections',
                'footnotes' => $footnotes !== [] ? 'referenced_source_nodes' : 'none_or_unavailable',
                'headers_footers' => 'unavailable',
                'lists' => 'explicit_num_id_and_level_without_markers',
                'tracked_changes' => 'deleted_text_excluded']);
    }

    public function preflightPdf(string $path): int
    {
        $maxPages = config('document_processing.pdf_max_pages');
        $timeout = config('document_processing.pdf_preflight_timeout_seconds');
        $uploadKb = config('document_processing.max_upload_size_kb');
        if (! is_int($maxPages) || $maxPages < 1 || ! is_int($timeout) || $timeout < 1
            || ! is_int($uploadKb) || $uploadKb < 1) {
            throw new \RuntimeException('Invalid PDF resource policy.');
        }
        if (filesize($path) === false || filesize($path) > $uploadKb * 1024) {
            throw new \RuntimeException('PDF file size limit exceeded.');
        }
        $process = new Process(['pdfinfo', $path]);
        $process->setTimeout($timeout);
        try {
            $process->run();
        } catch (\Throwable) {
            throw new \RuntimeException('PDF preflight failed.');
        }
        $info = $process->getOutput();
        if (! $process->isSuccessful() || preg_match('/^Encrypted:\s+yes\b/im', $info)) {
            throw new \RuntimeException('PDF is malformed or encrypted.');
        }
        if (! preg_match('/^Pages:\s+(\d+)\s*$/im', $info, $match) || (int) $match[1] < 1) {
            throw new \RuntimeException('PDF page count is unavailable.');
        }
        if ((int) $match[1] > $maxPages) {
            throw new \RuntimeException('PDF page limit exceeded.');
        }

        return (int) $match[1];
    }

    private function extractWordXml(
        \DOMNode $node,
        string $namespace,
        int $depth = 0
    ): string {
        if ($depth > 64) {
            throw new \RuntimeException('DOCX XML nesting limit exceeded.');
        }
        if ($node->namespaceURI === $namespace) {
            if (
                in_array(
                    $node->localName,
                    ['del', 'moveFrom', 'instrText'],
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
                $namespace,
                $depth + 1
            );
        }

        if ($node->namespaceURI === $namespace) {
            return match ($node->localName) {
                'p', 'tr' => rtrim(
                    $text,
                    "\n |"
                )."\n",

                'tc' => trim($text).' | ',

                default => $text,
            };
        }

        return $text;
    }
}
