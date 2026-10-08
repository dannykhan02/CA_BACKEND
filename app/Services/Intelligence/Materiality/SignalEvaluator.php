<?php

namespace App\Services\Intelligence\Materiality;

use App\Services\AI\Incremental\EvidenceMerger;
use App\Services\Intelligence\SeverityNormalizer;
use App\Services\Intelligence\Values\ValueParser;

/** Pure, bounded signal values over accepted read-model records. */
class SignalEvaluator
{
    public function __construct(private ValueParser $values, private EvidenceMerger $normalizer) {}

    /** @param array<string,mixed> $record @param list<array<string,mixed>> $records @param array<string,mixed> $context @return array<string,array<string,mixed>> */
    public function values(array $record, array $records, array $context, \DateTimeImmutable $asOf): array
    {
        $settings = config('intelligence_v2.materiality.signals');
        $typed = $record['typed']['value'] ?? null;
        $date = $this->date($record);
        $resolution = $date['resolution'] ?? 'unknown';
        $signal = [];
        $signal['severity'] = ['value' => $settings['severity'][SeverityNormalizer::normalize($record['data']['severity'] ?? null) ?? ''] ?? 0.0];
        $signal['date_proximity'] = ['value' => $this->proximity($date, $record, $asOf, $settings['date_proximity'])];
        $signal['date_resolution'] = ['value' => $settings['date_resolution'][$resolution] ?? $settings['date_resolution']['unknown']];
        $signal['attribution_authority'] = ['value' => $settings['attribution_authority'][$record['provenance']['attribution']['role'] ?? ''] ?? 0.0];
        $signal['obligation_consequence'] = ['value' => $this->consequence($record) ? 1.0 : 0.0];
        $signal['structural_prominence'] = $this->prominence($record, $context, $settings['structural_prominence']);
        $signal['monetary_magnitude'] = ['value' => $this->magnitude($record, $records, $typed, $settings['monetary_magnitude'])];
        $signal['relative_magnitude'] = ['value' => $this->relativeMagnitude($record, $records, $typed)];
        $signal['comparability'] = ['value' => isset($context['comparable_source_ids'][$record['source_id'] ?? '']) ? 1.0 : 0.0];
        $signal['repetition_penalty'] = ['value' => $this->isRepeat($record, $records) ? 1.0 : 0.0];
        $signal['boilerplate_penalty'] = $settings['boilerplate_penalty']['boilerplate_span_types'] === []
            ? ['value' => 0.0, 'skipped' => true, 'reason' => 'span_type_unavailable']
            : ['value' => in_array($record['span_type'] ?? null, $settings['boilerplate_penalty']['boilerplate_span_types'], true) ? 1.0 : 0.0];
        $signal['unresolved_penalty'] = ['value' => ($record['kind'] ?? null) === 'unresolved'
            && empty($record['data']['resolved_evidence_id']) ? 1.0 : 0.0];
        $signal['cited_by_synthesis'] = ['value' => isset($context['cited_source_ids'][$record['source_id'] ?? '']) ? 1.0 : 0.0];

        return $signal;
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    public function date(array $record): array
    {
        foreach (['due_date', 'observed_date', 'effective_date', 'expiry_date', 'review_date', 'as_of_date', 'period_covered', 'issued_date'] as $role) {
            if (isset($record['typed']['dates'][$role])) {
                return $record['typed']['dates'][$role];
            }
        }

        return [];
    }

    /** @param array<string,mixed> $date @param array<string,mixed> $record @param array<string,mixed> $settings */
    private function proximity(array $date, array $record, \DateTimeImmutable $asOf, array $settings): float
    {
        if (! in_array($record['status'] ?? null, [null, 'open'], true)) {
            return 0.0;
        }
        $target = ($date['resolution'] ?? null) === 'calendar' ? ($date['date'] ?? null)
            : (($date['resolution'] ?? null) === 'period' && ($date['period']['anchored'] ?? false)
                ? ($date['period']['end'] ?? null) : null);
        if (! is_string($target)) {
            return 0.0;
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $target);
        if (! $day) {
            return 0.0;
        }
        $days = (int) $asOf->setTime(0, 0)->diff($day)->format('%r%a');
        if ($days <= $settings['imminent_days']) {
            return 1.0;
        }
        if ($days > $settings['horizon_days']) {
            return $settings['floor_value'];
        }

        return 1.0 - $settings['falloff'] * ($days - $settings['imminent_days'])
            / ($settings['horizon_days'] - $settings['imminent_days']);
    }

    /** @param array<string,mixed> $record */
    public function consequence(array $record): bool
    {
        if (($record['kind'] ?? null) !== 'obligation') {
            return false;
        }
        foreach ($record['sources'] ?? [] as $source) {
            $quote = $source['quote'] ?? null;
            if (! is_string($quote)) {
                continue;
            }
            foreach (config('intelligence_v2.materiality.penalty_patterns') as $pattern) {
                if (! preg_match('/(?<!\p{L})'.preg_quote($pattern, '/').'(?!\p{L})/iu', $quote)) {
                    continue;
                }
                // The parser is authoritative: ambiguous numerical phrases are rejected.
                $parsed = $this->values->parse($record['data'] ?? [], [$quote]);
                if (in_array($parsed['value']['type'] ?? null, ['money', 'percent'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string,mixed> $record @param array<string,mixed> $context @param array<string,mixed> $settings @return array<string,mixed> */
    private function prominence(array $record, array $context, array $settings): array
    {
        $count = $context['span_count'] ?? null;
        $ordinal = $record['span_ordinal'] ?? null;
        $type = $record['span_type'] ?? null;
        if ($count === null || $count === 0) {
            return ['value' => 0.0, 'skipped' => true, 'reason' => 'span_data_unavailable'];
        }
        $heading = $settings['heading_span_types'];
        $value = in_array($type, $heading, true) ? $settings['heading_value'] : 0.0;
        if (is_int($ordinal) && $ordinal <= (int) ceil($count * $settings['ordinal_first_fraction'])) {
            $value = max($value, $settings['ordinal_value']);
        }

        return $heading === [] ? ['value' => $value, 'skipped' => true, 'reason' => 'span_type_unavailable']
            : ['value' => $value];
    }

    /** @param array<string,mixed>|null $typed @param list<array<string,mixed>> $records @param array<string,mixed> $record @param array<string,mixed> $settings */
    private function magnitude(array $record, array $records, ?array $typed, array $settings): float
    {
        if (($record['kind'] ?? null) !== 'metric' || ($typed['unit_kind'] ?? null) !== 'currency'
            || ! is_numeric($typed['number'] ?? null)) {
            return 0.0;
        }
        $group = $this->numericGroup($typed, $records);
        if (count($group) === 1) {
            return $settings['singleton_value'];
        }
        $smaller = count(array_filter($group, fn ($number) => $number < (float) $typed['number']));

        return $smaller / (count($group) - 1);
    }

    /** @param array<string,mixed>|null $typed @param list<array<string,mixed>> $records @param array<string,mixed> $record */
    private function relativeMagnitude(array $record, array $records, ?array $typed): float
    {
        if (($record['kind'] ?? null) !== 'metric' || ! is_numeric($typed['number'] ?? null)) {
            return 0.0;
        }
        $group = $this->numericGroup($typed, $records);
        $max = max(array_map('abs', $group));

        return $max == 0.0 ? 0.0 : abs((float) $typed['number']) / $max;
    }

    /** @param array<string,mixed> $typed @param list<array<string,mixed>> $records @return list<float> */
    private function numericGroup(array $typed, array $records): array
    {
        $numbers = [];
        foreach ($records as $candidate) {
            $value = $candidate['typed']['value'] ?? null;
            if (($candidate['kind'] ?? null) === 'metric' && ($value['unit_kind'] ?? null) === ($typed['unit_kind'] ?? null)
                && ($value['currency'] ?? null) === ($typed['currency'] ?? null) && is_numeric($value['number'] ?? null)) {
                $numbers[] = (float) $value['number'];
            }
        }

        return $numbers;
    }

    /** @param array<string,mixed> $record @param list<array<string,mixed>> $records */
    private function isRepeat(array $record, array $records): bool
    {
        $key = $this->repeatKey($record);
        $matches = array_values(array_filter($records, fn ($candidate) => $this->repeatKey($candidate) === $key));
        if (count($matches) < 2) {
            return false;
        }
        usort($matches, fn ($a, $b) => MaterialityScorer::compareTiebreak($a, $b));

        return ($matches[0]['identity'] ?? null) !== ($record['identity'] ?? null);
    }

    /** @param array<string,mixed> $record */
    private function repeatKey(array $record): string
    {
        $data = $record['data'] ?? [];

        return implode('|', array_map($this->normalizer->normalize(...), [
            (string) ($record['kind'] ?? ''), (string) ($data['label'] ?? ''),
            (string) ($data['value'] ?? ''), (string) ($data['period'] ?? ''),
        ]));
    }
}
