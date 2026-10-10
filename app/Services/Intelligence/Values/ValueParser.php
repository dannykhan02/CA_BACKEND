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

        if ($value !== '' && $this->citesValue($value, $quotes)) {
            $measurement = $this->measurements->parse($value, $record['unit'] ?? null, $record['label'] ?? null);
            if ($measurement !== null) {
                $kind = match ($measurement->kind) {
                    'currency' => 'money', 'percent' => 'percent', 'ratio' => 'ratio',
                    'count' => 'count', default => 'number',
                };
                $unitKind = in_array($measurement->kind, ['currency', 'percent', 'ratio', 'count', 'duration'], true)
                    ? $measurement->kind : 'other';
                // A value that is itself a time span is a span, not a metric. The parser can only
                // read a duration off the `unit` field, so "within 10 days" with no unit arrives as
                // an unclassified number - and a bare number grounds any claim that happens to
                // mention 10. Typed as a duration it grounds none of them, and the span is still
                // checked as a span through the record's relative date below.
                if ($measurement->kind === 'unknown' && $this->measurements->statesDuration($value)) {
                    $unitKind = 'duration';
                }
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

    /**
     * Whether one of the record's own cited quotes states this value.
     *
     * Compared with runs of whitespace collapsed, because a figure in a chart label reaches the
     * quote the way the source laid it out - "Total\n$1.584\nbillion", "$8.263 \nbillion" - while
     * the extracted value reads "$1.584 billion". Same characters, broken by the wrapping. Nothing
     * else is normalized: the digits, the scale word and the currency marker must all still be
     * present, in that order, in this record's own quote.
     *
     * @param  list<string>  $quotes
     */
    private function cited(string $raw, array $quotes): bool
    {
        $needle = $this->collapse($raw);
        if ($needle === '') {
            return false;
        }
        foreach ($quotes as $quote) {
            if (str_contains($this->collapse($quote), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The same check for the observed value, plus the digit guard a figure needs.
     *
     * A match sitting inside a longer number is not a statement of this value: "5%" occurs in a
     * funding chart's quote only inside "45%", which is the neighbouring bar, not this record's
     * figure. Typing it would hand a claim a number the chart never carried.
     *
     * Scoped to the value deliberately. A period is not a figure, and the same guard applied to one
     * would refuse "2022" out of an axis rendered "20242023202220212020" - where, unlike "5%" in
     * "45%", the digits really are that period, merely unseparated by the extractor.
     *
     * @param  list<string>  $quotes
     */
    private function citesValue(string $raw, array $quotes): bool
    {
        $needle = $this->collapse($raw);
        if ($needle === '') {
            return false;
        }
        $pattern = '/(?<![\d.,])'.preg_quote($needle, '/').'(?![\d])(?![.,]\d)/u';
        foreach ($quotes as $quote) {
            if (preg_match($pattern, $this->collapse($quote)) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Runs of whitespace reduced to one space, so a wrapped source line compares as written. */
    private function collapse(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
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
        foreach (EvidenceDateRecognizer::datesIn($quote) as $candidate) {
            if ($candidate['date'] === $iso && EvidenceDateRecognizer::statesDate($candidate['raw'], $date)) {
                return $candidate['raw'];
            }
        }

        return null;
    }
}
