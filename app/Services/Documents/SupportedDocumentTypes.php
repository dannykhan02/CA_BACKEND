<?php

namespace App\Services\Documents;

class SupportedDocumentTypes
{
    /** All type keys the schema knows about, enabled or not. */
    public function allTypes(): array
    {
        return array_keys(config('document_types.types'));
    }

    /** Only types currently accepted for upload. */
    public function enabledTypes(): array
    {
        return array_keys(array_filter(
            config('document_types.types'),
            fn (array $def) => $def['enabled'] === true
        ));
    }

    public function isEnabled(string $type): bool
    {
        return config("document_types.types.{$type}.enabled") === true;
    }

    public function isOcrEligible(string $type): bool
    {
        return config("document_types.types.{$type}.ocr_eligible") === true;
    }

    /** Resolves a file extension (case-insensitive, leading dot optional) to a type key, or null if unknown. */
    public function typeForExtension(string $extension): ?string
    {
        $extension = strtolower(ltrim($extension, '.'));

        foreach (config('document_types.types') as $type => $def) {
            if (in_array($extension, $def['extensions'], true)) {
                return $type;
            }
        }

        return null;
    }

    /** Flat extension list for enabled types only — feeds validation rules directly. */
    public function enabledExtensions(): array
    {
        return collect(config('document_types.types'))
            ->filter(fn (array $def) => $def['enabled'] === true)
            ->flatMap(fn (array $def) => $def['extensions'])
            ->values()
            ->all();
    }

    /** Flat MIME list for enabled types only. */
    public function enabledMimeTypes(): array
    {
        return collect(config('document_types.types'))
            ->filter(fn (array $def) => $def['enabled'] === true)
            ->flatMap(fn (array $def) => $def['mime_types'])
            ->values()
            ->all();
    }
}
