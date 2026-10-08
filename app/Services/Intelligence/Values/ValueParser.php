<?php

namespace App\Services\Intelligence\Values;

use App\Services\AI\Incremental\EvidenceDateRecognizer;
use App\Services\Intelligence\MeasurementParser;
use App\Services\Intelligence\PeriodParser;
use App\Services\Intelligence\ReportingPeriod;

/** Derives typed values from accepted, stored evidence without changing its source strings. */
class ValueParser
{
    public function __construct(private MeasurementParser $measurements, private PeriodParser $periods) {}

    /**
     * @param  array<string,mixed>  $record
     * @param  list<string>  $quotes  resolved cited quotes, never model-supplied replacements
     * @return array{value:array<string,mixed>|null,dates:array<string,array<string,mixed>>,extras:array<string,mixed>}
     */
    public function parse(array $record, array $quotes, ?string $confirmedEntityId = null): array
    {
        $value = (string) ($record['value'] ?? '');
        $periodText = (string) ($record['period'] ?? '');
        $due = (string) ($record['due_date'] ?? '');
        $quote = implode("\n", $quotes);
        $typed = ['value' => null, 'dates' => [], 'extras' => []];

        if ($value !== '' && $this->cited($value, $quotes)) {
            $measurement = $this->measurements->parse($value, $record['unit'] ?? null, $record['label'] ?? null);
            if ($measurement !== null) {
                $kind = match ($measurement->kind) {
                    'currency' => 'money', 'percent' => 'percent', 'ratio' => 'ratio',
                    'count' => 'count', default => 'number',
                };
                $unitKind = in_array($measurement->kind, ['currency', 'percent', 'ratio', 'count', 'duration'], true)
                    ? $measurement->kind : 'other';
                $rawOnly = $this->measurements->parse($value, null, $record['label'] ?? null);
                $typed['value'] = $this->shape($kind, $value, $record, $confirmedEntityId) + [];
                $typed['value']['number'] = $measurement->magnitude;
                $typed['value']['scale'] = $measurement->scale;
                $typed['value']['scale_source'] = $measurement->scale === 1.0 ? null
                    : (($rawOnly?->scale === $measurement->scale) ? 'stated' : 'unit');
                $typed['value']['currency'] = $measurement->currency;
                $typed['value']['unit'] = $record['unit'] ?? null;
                $typed['value']['unit_kind'] = $unitKind;
                $typed['value']['sign'] = $measurement->magnitude <=> 0;
                $typed['value']['precision'] = preg_match('/(?:\babout\b|\bcirca\b|~)/iu', $value)
                    ? 'approximate' : 'exact';
            } elseif (($parsed = $this->periods->parse($value)) !== null) {
                $typed['value'] = $this->temporal('period', $value, $record, $confirmedEntityId);
                $typed['value']['period'] = $this->period($value, $parsed);
            }
        }

        if ($periodText !== '' && $this->cited($periodText, $quotes)
            && ($parsed = $this->periods->parse($periodText)) !== null) {
            $role = in_array($record['kind'] ?? null, ['deadline', 'obligation'], true)
                ? 'due_date' : 'period_covered';
            $date = $this->temporal('period', $periodText, $record, $confirmedEntityId);
            $date['resolution'] = 'period';
            $date['period'] = $this->period($periodText, $parsed);
            $typed['dates'][$role] = $date;
        }

        if (($record['date_type'] ?? null) === 'explicit' && $due !== '') {
            $raw = $this->rawDate($due, $quote);
            if ($raw !== null) {
                $date = $this->temporal('date', $raw, $record, $confirmedEntityId);
                $date['date'] = $due;
                $date['resolution'] = 'calendar';
                $typed['dates']['due_date'] = $date;
            }
        } elseif (($record['date_type'] ?? null) === 'relative' && $value !== '' && $this->cited($value, $quotes)) {
            $date = $this->temporal('duration', $value, $record, $confirmedEntityId);
            $date['duration'] = ['text' => $value, 'iso8601' => null,
                'anchor_text' => null, 'anchor_resolved' => false];
            $date['resolution'] = 'relative';
            $typed['dates']['due_date'] = $date;
        }

        return $typed;
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function shape(string $type, string $raw, array $record, ?string $confirmedEntityId): array
    {
        $status = null;
        if (($record['kind'] ?? null) === 'metric') {
            foreach (['metric_type', 'value_basis'] as $field) {
                if (in_array($record[$field] ?? null, ['actual', 'forecast', 'target'], true)) {
                    $status = $record[$field];
                    break;
                }
            }
        }

        return [
            'type' => $type, 'raw' => $raw, 'verbatim' => true,
            'number' => null, 'scale' => null, 'scale_source' => null, 'currency' => null,
            'unit' => null, 'unit_kind' => 'other', 'sign' => null, 'precision' => 'exact',
            'measure_status' => $status,
            'entity_ref' => ['id' => $confirmedEntityId, 'text' => (string) ($record['subject'] ?? '')],
            'date' => null, 'period' => null, 'duration' => null,
            'parser_version' => config('intelligence_v2.values.parser_version'),
        ];
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function temporal(string $type, string $raw, array $record, ?string $confirmedEntityId): array
    {
        return $this->shape($type, $raw, $record, $confirmedEntityId);
    }

    /** @param list<string> $quotes */
    private function cited(string $raw, array $quotes): bool
    {
        foreach ($quotes as $quote) {
            if (str_contains($quote, $raw)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed> */
    private function period(string $raw, ReportingPeriod $parsed): array
    {
        $start = null;
        $end = null;
        if ($parsed->basis === 'calendar') {
            $label = $parsed->label;
            if ($parsed->granularity === 'day') {
                $start = $end = $label;
            } elseif ($parsed->granularity === 'month' && preg_match('/^(\d{4})-(\d{2})$/D', $label, $parts)) {
                $start = $label.'-01';
                $end = date('Y-m-t', strtotime($start));
            } elseif ($parsed->granularity === 'year' && preg_match('/^\d{4}$/D', $label)) {
                $start = $label.'-01-01';
                $end = $label.'-12-31';
            } elseif ($parsed->granularity === 'quarter' && preg_match('/^Q([1-4]) (\d{4})$/D', $label, $parts)) {
                $month = ((int) $parts[1] - 1) * 3 + 1;
                $start = sprintf('%s-%02d-01', $parts[2], $month);
                $end = date('Y-m-t', strtotime(sprintf('%s-%02d-01', $parts[2], $month + 2)));
            } elseif ($parsed->granularity === 'half' && preg_match('/^H([12]) (\d{4})$/D', $label, $parts)) {
                $month = ((int) $parts[1] - 1) * 6 + 1;
                $start = sprintf('%s-%02d-01', $parts[2], $month);
                $end = date('Y-m-t', strtotime(sprintf('%s-%02d-01', $parts[2], $month + 5)));
            }
        }

        return ['text' => $raw, 'grain' => $parsed->basis === 'fiscal' && $parsed->granularity === 'year'
            ? 'fiscal_year' : $parsed->granularity,
            'start' => $start, 'end' => $end, 'anchored' => $start !== null && $end !== null,
            'fiscal_year_basis' => null];
    }

    private function rawDate(string $iso, string $quote): ?string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $iso) || ! checkdate((int) substr($iso, 5, 2),
            (int) substr($iso, 8, 2), (int) substr($iso, 0, 4))) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $iso);
        if ($date === false || $date->format('Y-m-d') !== $iso) {
            return null;
        }
        preg_match_all('/(?<!\d)\d{4}[-\/.]\d{1,2}[-\/.]\d{1,2}(?!\d)|(?<!\d)\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{4}(?!\d)|(?<!\d)\d{1,2}(?:st|nd|rd|th)?[\s,.-]+[A-Za-z]{3,9}\.?(?:[\s,.-]+)\d{4}(?!\d)|(?<!\w)[A-Za-z]{3,9}\.?[\s,.-]+\d{1,2}(?:st|nd|rd|th)?[\s,.-]+\d{4}(?!\d)/iu',
            $quote, $matches);
        foreach ($matches[0] as $candidate) {
            if (EvidenceDateRecognizer::statesDate($candidate, $date)) {
                return $candidate;
            }
        }

        return null;
    }
}
