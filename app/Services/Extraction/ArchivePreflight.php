<?php

namespace App\Services\Extraction;

/** ZIP metadata gate before XML or workbook loaders expand uploaded archives. */
class ArchivePreflight
{
    public function check(string $path): void
    {
        $limits = config('document_processing.archive_limits');
        $uploadKb = config('document_processing.max_upload_size_kb');
        $required = ['max_entries', 'max_expanded_bytes', 'max_entry_bytes', 'max_ratio'];
        if (! is_int($uploadKb) || $uploadKb < 1 || ! is_array($limits)
            || array_diff($required, array_keys($limits)) !== [] || count($limits) !== count($required)
            || count(array_filter($limits, fn ($v) => is_int($v) && $v > 0)) !== count($required)
            || $limits['max_entry_bytes'] > $limits['max_expanded_bytes']
            || $limits['max_expanded_bytes'] < $uploadKb * 1024) {
            throw new \RuntimeException('Invalid archive resource policy.');
        }
        if (filesize($path) === false || filesize($path) > (int) config('document_processing.max_upload_size_kb') * 1024) {
            throw new \RuntimeException('Document file size limit exceeded.');
        }
        $zip = new \ZipArchive;
        if ($zip->open($path, \ZipArchive::CHECKCONS) !== true) {
            throw new \RuntimeException('Invalid document archive.');
        }
        try {
            if ($zip->numFiles > $limits['max_entries']) {
                throw new \RuntimeException('Document archive entry limit exceeded.');
            }
            $expanded = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                if (! is_array($entry) || ! isset($entry['size'], $entry['comp_size'])
                    || $entry['size'] < 0 || $entry['comp_size'] < 0) {
                    throw new \RuntimeException('Invalid document archive entry.');
                }
                if ($entry['size'] > $limits['max_entry_bytes']) {
                    throw new \RuntimeException('Document archive entry limit exceeded.');
                }
                $expanded += $entry['size'];
                if ($expanded > $limits['max_expanded_bytes']) {
                    throw new \RuntimeException('Document archive expansion limit exceeded.');
                }
                if ($entry['size'] > 0 && $limits['max_ratio'] < $entry['size'] / max(1, $entry['comp_size'])) {
                    throw new \RuntimeException('Document archive compression ratio exceeded.');
                }
            }
        } finally {
            $zip->close();
        }
    }
}
