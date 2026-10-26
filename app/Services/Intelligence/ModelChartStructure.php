<?php

namespace App\Services\Intelligence;

/** Rendering safety only. This class never establishes source provenance. */
final class ModelChartStructure
{
    public function inspect(string $type, mixed $data, string $title = ''): array
    {
        $fail = static fn (string $reason): array => ['eligible' => false, 'reason' => $reason, 'unit' => null];
        if (! in_array($type, ['bar', 'line', 'pie'], true) || ! is_array($data)
            || ! array_is_list($data) || count($data) < 2) {
            return $fail('Unsupported chart type or malformed point list.');
        }
        $labels = [];
        $units = [];
        $sum = 0.0;
        if (preg_match('/\((ha|m²|m2|km²|km2|kg|t|%|[A-Z]{3})\)\s*$/u', $title, $titleUnit)) {
            $units[$titleUnit[1]] = true;
        }
        foreach ($data as $point) {
            if (! is_array($point) || ! is_string($point['label'] ?? null)
                || ($label = trim($point['label'])) === ''
                || ! (is_int($point['value'] ?? null) || is_float($point['value'] ?? null))
                || ! is_finite((float) $point['value'])) {
                return $fail('A plotted value or category is missing or invalid.');
            }
            $key = mb_strtolower(preg_replace('/\s+/u', ' ', $label));
            if (isset($labels[$key])) {
                return $fail('Duplicate categories have ambiguous meaning.');
            }
            $labels[$key] = true;
            if (preg_match('/^(?:sub[\s-]*total|grand[\s-]*total|total)\b/iu', $label)) {
                return $fail('Totals and subtotals cannot be mixed with detail values.');
            }
            if (isset($point['children']) || isset($point['series']) || isset($point['value_unit'])) {
                return $fail('Nested or separately unitized model values are unsupported.');
            }
            if (preg_match('/\((ha|m²|m2|km²|km2|kg|t|%|[A-Z]{3})\)\s*$/u', $label, $match)) {
                $units[$match[1]] = true;
            }
            $sum += (float) $point['value'];
        }
        if (count($units) > 1) {
            return $fail('The points name incompatible units on one axis.');
        }
        if ($type === 'line') {
            $periods = array_map(static fn ($point) => trim($point['label']), $data);
            if (count(array_filter($periods, static fn ($label) => preg_match(
                '/^(?:\d{4}(?:-\d{2}(?:-\d{2})?)?|Q[1-4]\s+(?:FY)?\d{4})$/iuD', $label) === 1)) !== count($periods)
                || $periods !== array_values(array_unique($periods))) {
                return $fail('A line chart needs an explicit ordered time axis.');
            }
            $keys = array_map(static function ($label) {
                if (preg_match('/^Q([1-4])\s+(?:FY)?(\d{4})$/iuD', $label, $parts)) {
                    return (int) $parts[2] * 4 + (int) $parts[1];
                }

                return null;
            }, $periods);
            $quarterCount = count(array_filter($keys, static fn ($key) => $key !== null));
            if ($quarterCount > 0 && $quarterCount !== count($keys)) {
                return $fail('A line chart cannot mix quarter and calendar labels.');
            }
            if ($quarterCount === count($keys)) {
                $ordered = $keys;
                sort($ordered, SORT_NUMERIC);
            } else {
                $ordered = $periods;
                sort($ordered, SORT_STRING);
            }
            if ($ordered !== ($quarterCount > 0 ? $keys : $periods)) {
                return $fail('Time periods are not in chronological order.');
            }
        }
        if ($type === 'pie') {
            if (count($units) !== 1 || ! isset($units['%'])
                || count(array_filter($data, static fn ($point) => (float) $point['value'] > 0)) !== count($data)
                || abs($sum - 100) > 0.5) {
                return $fail('A pie chart needs positive percentages that describe one complete whole.');
            }
        }

        return ['eligible' => true, 'reason' => null, 'unit' => array_key_first($units)];
    }
}
