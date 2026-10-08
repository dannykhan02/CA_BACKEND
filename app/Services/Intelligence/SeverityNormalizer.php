<?php

namespace App\Services\Intelligence;

/** One conservative severity vocabulary for stored risk values. */
class SeverityNormalizer
{
    public static function normalize(mixed $severity): ?string
    {
        $normalized = is_string($severity) ? strtolower(trim($severity)) : null;

        return in_array($normalized, ['low', 'medium', 'high', 'critical'], true) ? $normalized : null;
    }
}
