<?php

namespace App\Services\Intelligence;

/**
 * A vision response is evidence only for what it explicitly reports. This
 * validator never guesses a unit, period, series relationship, or chart type.
 */
class SourceChartContract
{
    public function __construct(private MeasurementParser $measurements) {}

    public function metadata(array $chart, array $source): array
    {
        $basis = in_array($chart['basis'] ?? null, ['time_series', 'categorical', 'composition'], true)
            ? $chart['basis'] : null;
        $axis = $chart['axis'] ?? null;
        $axis = is_array($axis) && $this->label($axis['x'] ?? null) && $this->label($axis['y'] ?? null)
            ? ['x' => trim($axis['x']), 'y' => trim($axis['y'])] : null;
        $unit = $this->unitLabel($chart['unit'] ?? null);
        $status = in_array($chart['valueStatus'] ?? null, ['exact', 'estimated', 'unavailable'], true)
            ? $chart['valueStatus'] : 'unavailable';
        $series = $this->series($chart['series'] ?? null);
        $points = $series === null ? $this->points($chart['data'] ?? null) : null;
        $type = $chart['type'] ?? null;
        $compatible = ($type === 'line' && $basis === 'time_series')
            || ($type === 'bar' && $basis === 'categorical')
            || ($type === 'pie' && $basis === 'composition' && $series === null);
        $sameDimensions = $series === null || $this->aligned($series);
        $labels = array_column($series === null ? ($points ?? []) : $series[0]['data'], 'label');
        $timeVerified = $type !== 'line' || $this->continuousPeriods($labels);
        $compositionVerified = $type !== 'pie' || ($unit === '%' && $points !== null
            && count($points) >= 3 && collect($points)->every(fn ($point) => $point['value'] >= 0 && $point['value'] <= 100)
            && abs(array_sum(array_column($points, 'value')) - 100) <= 0.5);
        $sourceRetained = is_string($source['path'] ?? null) && is_string($source['sha256'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/', $source['sha256'])
            && is_string($source['document_hash'] ?? null) && is_string($source['pipeline_key'] ?? null);
        $declaredInvalidUnit = collect($chart['series'] ?? [])->contains(fn ($item) => is_array($item)
            && array_key_exists('unit', $item) && $item['unit'] !== null && $this->unitLabel($item['unit']) === null);
        $unitsCompatible = ! $declaredInvalidUnit && ($series === null || collect($series)->every(fn ($item) => $item['unit'] === null
            || $item['unit'] === $unit));
        if (! $unitsCompatible) {
            $unit = null;
        }
        $valuesVerified = $sourceRetained && $status === 'exact' && ($points !== null || $series !== null);
        $dimensionsVerified = $sourceRetained && $basis !== null && $axis !== null && $unit !== null
            && $compatible && $sameDimensions && $unitsCompatible && $timeVerified && $compositionVerified;

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

    private function unitLabel(mixed $value): ?string
    {
        if (! $this->label($value)) {
            return null;
        }
        $unit = trim($value);

        return preg_match('/^(?:unavailable|unknown|n\/?a|none|not stated)$/iuD', $unit)
            || $this->measurements->parse('1', $unit) === null ? null : $unit;
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
            $valid[] = ['name' => $name, 'unit' => $this->unitLabel($item['unit'] ?? null),
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

    /** A line needs dated, consecutive periods. A title cannot supply missing date identity. */
    public function continuousPeriods(array $labels): bool
    {
        if (count($labels) < 2) {
            return false;
        }
        $previous = null;
        foreach ($labels as $label) {
            $text = trim((string) $label);
            $period = null;
            if (preg_match('/^FY\s*(20\d{2})(?:[-\/](?:20)?(\d{2}))?$/iD', $text, $match)) {
                if (isset($match[2]) && (int) $match[2] !== ((int) $match[1] + 1) % 100) {
                    return false;
                }
                $period = ['fiscal-year', (int) $match[1]];
            } elseif (preg_match('/^(20\d{2})$/D', $text, $match)) {
                $period = ['calendar-year', (int) $match[1]];
            } elseif (preg_match('/^Q([1-4])\s*(FY\s*)?(20\d{2})$/iD', $text, $match)) {
                $period = [trim($match[2]) === '' ? 'calendar-quarter' : 'fiscal-quarter', (int) $match[3] * 4 + (int) $match[1] - 1];
            } elseif (preg_match('/^H([1-2])\s*(FY\s*)?(20\d{2})$/iD', $text, $match)) {
                $period = [trim($match[2]) === '' ? 'calendar-half' : 'fiscal-half', (int) $match[3] * 2 + (int) $match[1] - 1];
            } elseif (preg_match('/^(FY)?(20\d{2})[-\/](0?[1-9]|1[0-2])$/iD', $text, $match)) {
                $period = [($match[1] ?? '') === '' ? 'calendar-month' : 'fiscal-month', (int) $match[2] * 12 + (int) $match[3] - 1];
            }
            if ($period === null || $previous !== null && ($period[0] !== $previous[0] || $period[1] !== $previous[1] + 1)) {
                return false;
            }
            $previous = $period;
        }

        return true;
    }
}
