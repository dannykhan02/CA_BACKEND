<?php

namespace App\Services\Extraction;

/** Joins parser-owned PDF pages with OCR of pages that have no native text. */
class PdfHybridAssembler
{
    /** @param array<int, array{text:string, confidence?:float, metadata?:array}> $ocrPages */
    public function assemble(StructuredDocument $native, array $ocrPages): StructuredDocument
    {
        if ($native->format !== 'PDF') {
            throw new \InvalidArgumentException('Hybrid assembly requires PDF page structure.');
        }

        $pages = [];
        foreach ($native->nodes as $node) {
            if (($node['type'] ?? null) !== 'page' || ! is_int($node['page'] ?? null)) {
                continue;
            }
            $pages[$node['page']] = $node;
        }
        if ($pages === [] || array_keys($pages) !== range(1, count($pages))) {
            throw new \InvalidArgumentException('PDF page manifest is incomplete.');
        }
        foreach ($ocrPages as $page => $result) {
            if (! isset($pages[$page]) || trim((string) ($pages[$page]['text'] ?? '')) !== ''
                || ! is_string($result['text'] ?? null)) {
                throw new \InvalidArgumentException('OCR page does not match an empty native page.');
            }
        }

        $parts = [];
        $nodes = [];
        $offset = 0;
        foreach ($pages as $number => $nativePage) {
            if ($number > 1) {
                $offset++;
            }
            $ocr = $ocrPages[$number] ?? null;
            $text = $ocr['text'] ?? ($nativePage['text'] ?? '');
            $node = ['id' => 'page:'.$number, 'type' => 'page', 'page' => $number,
                'path' => 'page:'.$number,
                'meta' => $ocr === null ? $nativePage['meta'] : [
                    'modality' => 'ocr', 'reading_order' => 'ocr_layout_unverified',
                    'confidence' => $ocr['confidence'] ?? null,
                    'geometry' => $ocr['metadata']['geometry'] ?? 'unavailable',
                    'tables' => 'unavailable',
                ]];
            if ($text !== '') {
                $node += ['start' => $offset, 'end' => $offset + mb_strlen($text), 'text' => $text];
            }
            $nodes[] = $node;
            $parts[$number] = $text;
            $offset += mb_strlen($text);
        }

        $capabilities = $native->capabilities;
        $capabilities['hybrid_ocr'] = $ocrPages === [] ? 'not_run' : 'empty_native_pages_only';
        $capabilities['ocr_pages'] = array_keys($ocrPages);
        $capabilities['empty_text_pages'] = array_keys(array_filter($parts,
            static fn (string $part): bool => trim($part) === ''));

        return new StructuredDocument('PDF', 'smalot-page-ocr.v1', implode("\f", $parts),
            $nodes, $capabilities);
    }
}
