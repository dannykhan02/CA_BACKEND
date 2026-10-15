<?php

namespace App\Services\Intelligence;

/**
 * A vision response is evidence only for what it explicitly reports. This
 * validator never guesses a unit, period, series relationship, or chart type.
 */
class SourceChartContract
{
    public function metadata(array $chart, array $source): array
    {
        $basis = in_array($chart['basis'] ?? null, ['time_series', 'categorical', 'composition'], true)
            ? $chart['basis'] : null;
        $axis = $chart['axis'] ?? null;
        $axis = is_array($axis) && $this->label($axis['x'] ?? null) && $this->label($axis['y'] ?? null)
            ? ['x' => trim($axis['x']), 'y' => trim($axis['y'])] : null;
        $unit = $this->label($chart['unit'] ?? null) ? trim($chart['unit']) : null;
        $status = in_array($chart['valueStatus'] ?? null, ['exact', 'estimated', 'unavailable'], true)
            ? $chart['valueStatus'] : 'unavailable';
        $series = $this->series($chart['series'] ?? null);
        $points = $series === null ? $this->points($chart['data'] ?? null) : null;
        $type = $chart['type'] ?? null;
        $compatible = ($type === 'line' && $basis === 'time_series')
            || ($type === 'bar' && $basis === 'categorical')
            || ($type === 'pie' && $basis === 'composition' && $series === null);
        $sameDimensions = $series === null || $this->aligned($series);
        $sourceRetained = is_string($source['path'] ?? null) && is_string($source['sha256'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/', $source['sha256'])
            && is_string($source['document_hash'] ?? null) && is_string($source['pipeline_key'] ?? null);
        $unitsCompatible = $series === null || collect($series)->every(fn ($item) => $item['unit'] === null
            || $item['unit'] === $unit);
        if (! $unitsCompatible) {
            $unit = null;
        }
        $valuesVerified = $sourceRetained && $status === 'exact' && ($points !== null || $series !== null);
        $dimensionsVerified = $sourceRetained && $basis !== null && $axis !== null && $unit !== null
            && $compatible && $sameDimensions && $unitsCompatible;

        return [
            'origin' => 'document_visual',
            'page' => is_int($source['page'] ?? null) && $source['page'] > 0 ? $source['page'] : null,
            'path' => $source['path'] ?? null,
            'sha256' => $source['sha256'] ?? null,
            'document_hash' => $source['document_hash'] ?? null,
            'pipeline_key' => $source['pipeline_key'] ?? null,
            'media_type' => $source['media_type'] ?? null,
            'basis' => $basis,
            'axis' => $axis,
            'unit' => $unit,
            'value_status' => $status,
            'dimensions_verified' => $dimensionsVerified,
            'values_verified' => $valuesVerified,
            'series' => $series,
        ];
    }

    private function label(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function points(mixed $points): ?array
    {
        if (! is_array($points) || ! array_is_list($points) || count($points) < 2) {
            return null;
        }
        foreach ($points as $point) {
            if (! is_array($point) || ! $this->label($point['label'] ?? null)
                || ! is_int($point['value'] ?? null) && ! is_float($point['value'] ?? null)
                || ! is_finite((float) $point['value'])) {
                return null;
            }
        }

        return count(array_unique(array_column($points, 'label'))) === count($points) ? $points : null;
    }

    private function series(mixed $series): ?array
    {
        if (! is_array($series) || ! array_is_list($series) || count($series) < 2) {
            return null;
        }
        $names = [];
        $valid = [];
        foreach ($series as $item) {
            if (! is_array($item) || ! $this->label($item['name'] ?? null)
                || ($points = $this->points($item['data'] ?? null)) === null) {
                return null;
            }
            $name = trim($item['name']);
            $names[] = $name;
            $valid[] = ['name' => $name, 'unit' => $this->label($item['unit'] ?? null) ? trim($item['unit']) : null,
                'data' => $points];
        }

        return count(array_unique($names)) === count($names) ? $valid : null;
    }

    private function aligned(array $series): bool
    {
        $labels = array_column($series[0]['data'], 'label');
        foreach ($series as $item) {
            if (array_column($item['data'], 'label') !== $labels) {
                return false;
            }
        }

        return true;
    }
}
