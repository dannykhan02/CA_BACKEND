<?php

namespace App\Services\Documents;

/**
 * Single query surface over config/document_types.php — controllers and
 * validation rules call this instead of hardcoding mime/extension lists,
 * so enabling a new type is a config change, not a find-and-replace.
 */
class SupportedDocumentTypes
{
    public static function enabledMimeTypes(): array
    {
        return collect(config('document_types'))
            ->filter(fn ($t) => $t['enabled'])
            ->flatMap(fn ($t) => $t['mime_types'])
            ->values()
            ->all();
    }

    public static function enabledExtensions(): array
    {
        return collect(config('document_types'))
            ->filter(fn ($t) => $t['enabled'])
            ->flatMap(fn ($t) => $t['extensions'])
            ->values()
            ->all();
    }

    public static function typeForMime(string $mime): ?string
    {
        foreach (config('document_types') as $key => $type) {
            if ($type['enabled'] && in_array($mime, $type['mime_types'], true)) {
                return $key;
            }
        }
        return null;
    }

    public static function routeFor(string $documentType): ?string
    {
        return config("document_types.{$documentType}.route");
    }
}
