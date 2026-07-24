<?php

namespace App\Services;

use PhpOffice\PhpWord\IOFactory;
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
    public function extractPdfText(string $path): string
    {
        $parser = new PdfParser();
        return $parser->parseFile($path)->getText();
    }

    /**
     * Extracts text from a DOCX file with recursive table support.
     *
     * Critical fix: PhpOffice\PhpWord\Element\Table does NOT implement getText(),
     * so a naive extraction silently skips every table. Regulatory monitoring
     * reports carry their actual data (stats, percentages, figures) almost
     * entirely in tables, not narrative paragraphs, so this was a silent,
     * serious data-quality gap.
     */
    public function extractDocxText(string $path): string
    {
        $this->guardAgainstZipBomb($path);

        $phpWord = IOFactory::load($path);
        $text = '';

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $text .= $this->extractElementText($element);
            }
        }

        return $text;
    }

    /**
     * DOCX is a zip archive. A crafted file under the upload size cap can
     * still decompress to a much larger size in memory before PhpWord even
     * starts parsing — a classic zip-bomb pattern. This checks the sum of
     * uncompressed entry sizes from the zip's central directory (cheap,
     * no actual decompression) before IOFactory::load() touches it.
     */
    private function guardAgainstZipBomb(string $path): void
    {
        $maxUncompressedBytes = 200 * 1024 * 1024; // 200MB ceiling — generous for any legitimate report

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Could not open DOCX as a zip archive.');
        }

        $totalUncompressed = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $totalUncompressed += $stat['size'];
        }
        $zip->close();

        if ($totalUncompressed > $maxUncompressedBytes) {
            throw new \RuntimeException('Document exceeds safe decompressed size limits.');
        }
    }

    /**
     * Recursively extracts text from a PhpWord element. Two cases matter:
     * - Table does NOT implement getText() at all — must walk rows/cells manually,
     *   or every table in the document is silently skipped (regulatory reports
     *   carry most of their real data in tables, not narrative paragraphs).
     * - getText() is not guaranteed to return a string — Title in particular can
     *   be constructed from either a plain string or a TextRun object, and just
     *   returns whatever was passed in. Concatenating a TextRun object directly
     *   throws "could not be converted to string" — confirmed via real-document
     *   testing on 2026-07-24.
     */
    private function extractElementText($element): string
    {
        // TABLE: Critical case - extract all cells with pipe-delimited rows
        if ($element instanceof \PhpOffice\PhpWord\Element\Table) {
            $text = '';
            foreach ($element->getRows() as $row) {
                $cellTexts = [];
                foreach ($row->getCells() as $cell) {
                    $cellText = '';
                    foreach ($cell->getElements() as $cellElement) {
                        $cellText .= $this->extractElementText($cellElement);
                    }
                    $cellTexts[] = trim($cellText);
                }
                // Pipe-delimited row so Claude can still read table structure
                // (which column a figure belongs to) rather than a wall of
                // numbers with no positional meaning.
                $text .= implode(' | ', $cellTexts) . "\n";
            }
            return $text;
        }

        // getText() elements - handle various return types
        if (method_exists($element, 'getText')) {
            $t = $element->getText();

            // getText() can return an object (e.g., TextRun for Title elements)
            // rather than a string or array - recurse into it
            if (is_object($t)) {
                return $this->extractElementText($t) . "\n";
            }

            // getText() can return a nested array of runs
            if (is_array($t)) {
                return implode(' ', array_filter($t, 'is_string'));
            }

            // Plain string case
            return $t . "\n";
        }

        // Container elements like TextRun: recurse into their own elements
        if (method_exists($element, 'getElements')) {
            $text = '';
            foreach ($element->getElements() as $child) {
                $text .= $this->extractElementText($child);
            }
            return $text;
        }

        return '';
    }
}