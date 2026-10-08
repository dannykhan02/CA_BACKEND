<?php

namespace App\Services\Intelligence\Brief;

use App\Services\AI\Incremental\EvidenceMerger;
use App\Services\AI\Incremental\EvidenceDateRecognizer;
use App\Services\Intelligence\MeasurementParser;
use App\Services\Intelligence\NegativeClaimGuard;
use App\Services\Intelligence\Brief\Templates\DeterministicTemplates;

/** Local verification of prose against the records it cites. No provider or persistence path. */
class BriefVerifier
{
    public function __construct(private NegativeClaimGuard $negativeClaims,
        private MeasurementParser $measurements, private EvidenceMerger $normalizer,
        private DeterministicTemplates $templates) {}

    /** @param array<string,mixed> $block @param array<string,array<string,mixed>> $recordsBySource
     *  @param list<string> $availableSourceIds @return array<string,mixed>
     */
    public function verify(array $block, array $recordsBySource, array $availableSourceIds): array
    {
        $originalCites = array_key_exists('cites', $block) ? $block['cites'] : ($block['sourceIds'] ?? null);
        $cites = is_array($originalCites) ? $originalCites : [];
        $citesShapeValid = array_is_list($cites) && $cites !== [];
        foreach ($cites as $id) {
            $citesShapeValid = $citesShapeValid && is_string($id) && trim($id) !== '';
        }
        $cites = $citesShapeValid ? $cites : [];
        $records = array_values(array_filter(array_map(fn ($id) => $recordsBySource[$id] ?? null, $cites)));
        $text = trim((string) ($block['text'] ?? '').' '.(string) ($block['detail'] ?? ''));
        $checks = [];
        $add = static function (string $name, ?bool $passed, ?string $detail = null) use (&$checks): void {
            $checks[] = ['check' => $name, 'status' => $passed === null ? 'skipped' : ($passed ? 'passed' : 'failed'),
                'detail' => $detail];
        };
        $available = array_fill_keys($availableSourceIds, true);
        $absenceTemplate = ($block['origin'] ?? null) === 'docintel_deterministic'
            && ($block['assertion'] ?? null) === 'absent'
            && ($block['template_id'] ?? null) === 'absence.high_critical_risks';
        $citesValid = ($citesShapeValid || ($absenceTemplate && $originalCites === null))
            && count($cites) <= config('intelligence_v2.brief_limits.max_cites_per_block');
        foreach ($cites as $id) {
            $citesValid = $citesValid && isset($available[$id], $recordsBySource[$id]);
        }
        $add('cites_available', $citesValid);

        $numbers = $this->numbers($text);
        $add('numbers_grounded', $numbers === [] ? null : $this->numbersGrounded($numbers, $records, $block));
        $dates = $this->dates($text);
        $add('dates_grounded', $dates === [] ? null : $this->datesGrounded($dates, $records));
        $periods = $this->periods($text);
        $add('periods_grounded', $periods === [] ? null : $this->periodsGrounded($periods, $records));
        $entities = $this->entities($text);
        $add('entities_grounded', $entities === [] ? null : $this->entitiesGrounded($entities, $records, $recordsBySource));
        $unitTokens = $this->unitTokens($text);
        $add('units_consistent', $unitTokens === [] ? null : $this->unitsConsistent($unitTokens, $numbers, $records, $block));
        $direction = $this->comparisonDirection($text);
        $add('comparison_valid', $direction === null ? null : $this->comparisonValid($direction, $records));
        $approvedAbsence = ($block['origin'] ?? null) === 'docintel_deterministic'
            && ($block['assertion'] ?? null) === 'absent'
            && ($block['template_id'] ?? null) === 'absence.high_critical_risks'
            && ($block['absence_check']['predicate'] ?? null) === 'risk_severity_in(high,critical)'
            && ($block['absence_check']['matched'] ?? null) === 0;
        $add('negative_claim', $approvedAbsence || ! $this->negativeClaims->matchesProse($text));
        $reported = (bool) ($block['attribution']['reported'] ?? false);
        $add('attribution_respected', ! $reported || $this->namesAttribution($text, $block['attribution']));
        $legal = ['document' => ['stated'], 'docintel_deterministic' => ['derived', 'absent'],
            'docintel_ai' => ['stated', 'derived', 'inferred'], 'unknown' => ['unspecified']];
        $origin = $block['origin'] ?? null;
        $assertion = $block['assertion'] ?? null;
        $originConsistent = is_string($origin) && in_array($assertion, $legal[$origin] ?? [], true)
            && (! in_array($assertion, ['stated', 'absent'], true)
                || count(array_filter($records,
                    fn ($record) => ($record['provenance']['origin'] ?? null) === 'unknown')) === 0);
        foreach ($records as $record) {
            $recordOrigin = $record['provenance']['origin'] ?? null;
            $originConsistent = $originConsistent && is_string($recordOrigin)
                && in_array($record['provenance']['assertion'] ?? null, $legal[$recordOrigin] ?? [], true);
        }
        $add('origin_assertion_consistent', $originConsistent);
        $add('no_source_text_leak', ! $this->leaksQuote($text, $records));
        $failed = array_values(array_map(fn ($check) => $check['check'],
            array_filter($checks, fn ($check) => $check['status'] === 'failed')));

        return ['status' => $failed === [] ? 'passed' : 'failed', 'checks' => $checks,
            'failed_reasons' => $failed, 'verifier_version' => config('intelligence_v2.brief.verifier_version')];
    }

    /** @param array<string,mixed> $block @param array<string,array<string,mixed>> $recordsBySource
     *  @param list<string> $availableSourceIds @param list<array<string,mixed>> $allRecords
     *  @param array<string,string>|null $absenceRequest @return array{block:array<string,mixed>|null,rejected:bool,verification:array<string,mixed>}
     */
    public function admit(array $block, array $recordsBySource, array $availableSourceIds,
        array $coverage, array $allRecords, ?array $absenceRequest = null,
        ?callable $fallback = null): array
    {
        $verification = $this->verify($block, $recordsBySource, $availableSourceIds);
        if ($verification['status'] === 'passed') {
            $block['verification'] = $verification;
            $block['ai_generated'] = ($block['origin'] ?? null) === 'docintel_ai';

            return ['block' => $block, 'rejected' => false, 'verification' => $verification];
        }
        $replacement = null;
        if (in_array('negative_claim', $verification['failed_reasons'], true)) {
            $screened = $this->negativeClaims->screenAiBlock($block, $coverage, $allRecords, $absenceRequest, $fallback);
            $replacement = $screened['block'];
        } elseif ($fallback !== null) {
            $candidate = $fallback($block);
            $originalCites = (array) (array_key_exists('cites', $block)
                ? $block['cites'] : ($block['sourceIds'] ?? []));
            $candidateCites = is_array($candidate) ? (array) (array_key_exists('cites', $candidate)
                ? $candidate['cites'] : ($candidate['sourceIds'] ?? [])) : [];
            sort($originalCites);
            sort($candidateCites);
            if (is_array($candidate) && ($candidate['origin'] ?? null) === 'docintel_deterministic'
                && ($candidate['type'] ?? null) === ($block['type'] ?? null)
                && $originalCites !== [] && $candidateCites === $originalCites) {
                $replacement = $candidate;
            }
        }

        if (($replacement['assertion'] ?? null) === 'absent'
            && $this->negativeClaims->deterministicAbsence($coverage, $allRecords, $absenceRequest) === null) {
            $replacement = null;
        }
        if ($replacement !== null && $this->registeredFallback($replacement, $block, $recordsBySource)) {
            $fallbackVerification = $this->verify($replacement, $recordsBySource, $availableSourceIds);
            if ($fallbackVerification['status'] === 'passed') {
                $replacement['verification'] = $fallbackVerification;
                $replacement['ai_generated'] = false;

                return ['block' => $replacement, 'rejected' => true, 'verification' => $verification];
            }
        }

        return ['block' => null, 'rejected' => true, 'verification' => $verification];
    }

    private function registeredFallback(array $candidate, array $original, array $recordsBySource): bool
    {
        if (($candidate['origin'] ?? null) !== 'docintel_deterministic'
            || ($candidate['type'] ?? null) !== ($original['type'] ?? null)) {
            return false;
        }
        if (($candidate['template_id'] ?? null) === 'absence.high_critical_risks') {
            return ($candidate['text'] ?? null) === 'No high or critical risks were identified.'
                && ($candidate['assertion'] ?? null) === 'absent'
                && ($candidate['absence_check']['predicate'] ?? null) === 'risk_severity_in(high,critical)'
                && ($candidate['absence_check']['matched'] ?? null) === 0;
        }
        $registered = ['measure.period_value' => 'measure', 'timeline.calendar_due' => 'timeline',
            'timeline.period_due' => 'timeline', 'timeline.relative_due' => 'timeline',
            'attention.overdue' => 'attention', 'attention.imminent' => 'attention',
            'attention.critical_risk' => 'attention'];
        $id = $candidate['template_id'] ?? null;
        if (! is_string($id) || ($registered[$id] ?? null) !== ($candidate['type'] ?? null)
            || ! is_array($candidate['template_input'] ?? null)) {
            return false;
        }

        $input = $candidate['template_input'];
        if (array_diff(array_keys($input), ['label', 'value', 'date', 'period', 'attribution']) !== []
            || ($id === 'measure.period_value' && ! isset($input['value']))
            || (in_array($id, ['timeline.calendar_due', 'timeline.period_due', 'timeline.relative_due',
                'attention.overdue', 'attention.imminent'], true) && ! isset($input['date']))) {
            return false;
        }
        $groundedInput = false;
        foreach ($candidate['cites'] ?? [] as $sourceId) {
            if (! is_string($sourceId) || ! isset($recordsBySource[$sourceId])) {
                continue;
            }
            $record = $recordsBySource[$sourceId];
            if (($input['label'] ?? null) !== ($record['data']['label'] ?? null)
                || (isset($input['value']) && $input['value'] !== ($record['typed']['value'] ?? null))
                || (isset($input['date']) && ! in_array($input['date'], $record['typed']['dates'] ?? [], true))
                || (isset($input['attribution'])
                    && $input['attribution'] !== ($record['provenance']['attribution'] ?? null))
                || (isset($input['period']) && ! in_array($input['period'],
                    array_column(array_column($record['typed']['dates'] ?? [], 'period'), 'text'), true))) {
                continue;
            }
            $groundedInput = true;
            break;
        }

        return $groundedInput
            && ($candidate['text'] ?? null) === $this->templates->render($id, $input);
    }

    /** @return list<array{raw:string,number:float,unit:string|null}> */
    private function numbers(string $text): array
    {
        foreach ([...$this->dates($text), ...$this->periods($text)] as $temporal) {
            $text = str_replace($temporal, ' ', $text);
        }
        preg_match_all('/(?<![\p{L}\d])(?:[A-Z]{3}\s+)?[+-]?\d[\d,]*(?:\.\d+)?(?:\s*(?:trillion|billion|million|thousand|%|percent))?/iu',
            $text, $matches);
        $out = [];
        foreach ($matches[0] as $raw) {
            $raw = trim($raw);
            if (preg_match('/^\d{4}$/D', $raw) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $raw)) {
                continue;
            }
            $measurement = $this->measurements->parse($raw, null, null);
            if ($measurement !== null) {
                $out[] = ['raw' => $raw, 'number' => (float) $measurement->magnitude,
                    'unit' => $measurement->currency ?? ($measurement->kind === 'percent' ? '%' : null)];
            }
        }

        return $out;
    }

    /** @param list<array<string,mixed>> $numbers @param list<array<string,mixed>> $records */
    private function numbersGrounded(array $numbers, array $records, array $block): bool
    {
        foreach ($numbers as $number) {
            $matched = false;
            foreach ($records as $record) {
                $value = $record['typed']['value'] ?? null;
                if (! $this->validNumericValue($value)) {
                    continue;
                }
                if ($number['unit'] !== null && $number['unit'] !== ($value['currency'] ?? null)
                    && ! ($number['unit'] === '%' && ($value['unit_kind'] ?? null) === 'percent')) {
                    continue;
                }
                $target = (float) $value['number'];
                $exactFor = config('intelligence_v2.brief.numeric_tolerance.exact_for');
                $tolerance = ($value['precision'] ?? 'exact') === 'exact'
                    || (in_array('currency', $exactFor, true) && ($value['unit_kind'] ?? null) === 'currency')
                    || (in_array('integer', $exactFor, true) && floor($target) === $target)
                    ? 0.0 : $this->lastDigitTolerance((string) ($value['raw'] ?? $target));
                if (abs($number['number'] - $target) <= $tolerance) {
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                foreach ($records as $record) {
                    foreach ($record['typed']['dates'] ?? [] as $typedDate) {
                        $duration = $typedDate['duration']['text'] ?? null;
                        if (($typedDate['type'] ?? null) === 'duration'
                            && ($typedDate['resolution'] ?? null) === 'relative'
                            && ($typedDate['duration']['anchor_resolved'] ?? null) === false
                            && is_string($duration) && $duration !== ''
                            && ($typedDate['raw'] ?? null) === $duration
                            && preg_match('/(?<!\d)'.preg_quote($number['raw'], '/').'(?!\d)/u', $duration)) {
                            $matched = true;
                            break 2;
                        }
                    }
                }
            }
            if (! $matched && ! $this->derivedNumberMatches($number['number'], $block, $records)) {
                return false;
            }
        }

        return true;
    }

    private function lastDigitTolerance(string $raw): float
    {
        if (preg_match('/\.(\d+)/', $raw, $parts)) {
            return 0.5 * 10 ** (-strlen($parts[1]));
        }

        return 0.0;
    }

    private function validNumericValue(mixed $value): bool
    {
        if (! is_array($value) || ! in_array($value['type'] ?? null,
            ['number', 'money', 'percent', 'ratio', 'count'], true)
            || ! is_numeric($value['number'] ?? null)
            || ! in_array((float) ($value['scale'] ?? 0), [1.0, 1e3, 1e6, 1e9, 1e12], true)
            || ! in_array($value['unit_kind'] ?? null,
                ['currency', 'percent', 'ratio', 'count', 'other'], true)
            || ! in_array($value['precision'] ?? null, ['exact', 'rounded', 'approximate'], true)
            || ! is_string($value['raw'] ?? null) || $value['raw'] === '') {
            return false;
        }
        if (array_key_exists('measure_status', $value)
            && ! in_array($value['measure_status'], [null, 'actual', 'forecast', 'target'], true)) {
            return false;
        }
        $expectedKinds = ['number' => 'other', 'money' => 'currency', 'percent' => 'percent',
            'ratio' => 'ratio', 'count' => 'count'];
        if ($value['unit_kind'] !== $expectedKinds[$value['type']]) {
            return false;
        }
        if ($value['type'] === 'money') {
            if (! is_string($value['currency'] ?? null) || ! preg_match('/^[A-Z]{3}$/D', $value['currency'])) {
                return false;
            }
        } elseif (($value['currency'] ?? null) !== null) {
            return false;
        }
        $parsed = $this->measurements->parse($value['raw'], $value['unit'] ?? null, null);

        return $parsed !== null && (float) $parsed->magnitude === (float) $value['number']
            && (float) $parsed->scale === (float) $value['scale']
            && $parsed->currency === ($value['currency'] ?? null)
            && in_array($parsed->kind, match ($value['type']) {
                'money' => ['currency'], 'percent' => ['percent'], 'ratio' => ['ratio'],
                'count' => ['count'], default => ['unknown', 'change'],
            }, true);
    }

    /** @param list<array<string,mixed>> $records */
    private function derivedNumberMatches(float $number, array $block, array $records): bool
    {
        $derivation = $block['derivation'] ?? null;
        if (($derivation['operation'] ?? null) !== 'growth_percent'
            || count($derivation['inputs'] ?? []) !== 2) {
            return false;
        }
        $byId = [];
        foreach ($records as $record) {
            $byId[$record['source_id']] = $record;
        }
        [$from, $to] = $derivation['inputs'];
        $a = $byId[$from]['typed']['value'] ?? null;
        $b = $byId[$to]['typed']['value'] ?? null;
        if (! $this->validNumericValue($a) || ! $this->validNumericValue($b)
            || (float) $a['number'] == 0.0 || ! $this->comparable($a, $b)) {
            return false;
        }
        $computed = ((float) $b['number'] - (float) $a['number']) / abs((float) $a['number']) * 100;

        return abs($number - $computed) <= $this->lastDigitTolerance((string) ($derivation['display'] ?? $number));
    }

    /** @return list<string> */
    private function dates(string $text): array
    {
        return EvidenceDateRecognizer::candidatesIn($text);
    }

    /** @param list<string> $dates @param list<array<string,mixed>> $records */
    private function datesGrounded(array $dates, array $records): bool
    {
        foreach ($dates as $date) {
            $recognized = EvidenceDateRecognizer::datesIn($date);
            if ($recognized === []) {
                return false;
            }
            $canonical = $recognized[0]['date'];
            $found = false;
            foreach ($records as $record) {
                foreach ($record['typed']['dates'] ?? [] as $typed) {
                    $found = $found || (($typed['resolution'] ?? null) === 'calendar'
                        && ($typed['date'] ?? null) === $canonical);
                }
            }
            if (! $found) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function periods(string $text): array
    {
        foreach ($this->dates($text) as $date) {
            $text = str_replace($date, ' ', $text);
        }
        preg_match_all('/\bFY\s*\d{4}\b|\bQ[1-4]\s+\d{4}\b|\bH[12]\s+\d{4}\b|(?<![-\d])\b(?:19|20)\d{2}\b(?!-\d)/iu', $text, $matches);

        return $matches[0];
    }

    /** @param list<string> $periods @param list<array<string,mixed>> $records */
    private function periodsGrounded(array $periods, array $records): bool
    {
        $known = [];
        foreach ($records as $record) {
            foreach ($record['typed']['dates'] ?? [] as $date) {
                if (is_string($date['period']['text'] ?? null)) {
                    $known[] = $this->normalizer->normalize($date['period']['text']);
                }
            }
        }
        foreach ($periods as $period) {
            if (! in_array($this->normalizer->normalize($period), $known, true)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function entities(string $text): array
    {
        preg_match_all('/\b(?:\p{Lu}[\p{L}\p{M}]+\s+){1,}\p{Lu}[\p{L}\p{M}]+\b/u', $text, $matches);

        return $matches[0];
    }

    /** @param list<string> $entities @param list<array<string,mixed>> $records */
    private function entitiesGrounded(array $entities, array $records, array $recordsBySource): bool
    {
        $known = [];
        foreach ($records as $record) {
            $data = $record['data'] ?? [];
            $values = [$data['subject'] ?? null];
            if (($record['kind'] ?? null) === 'entity') {
                $values[] = $data['value'] ?? null;
                $values = [...$values, ...($data['aliases'] ?? [])];
            }
            $values = [...$values, ...($record['confirmed_entity_names'] ?? [])];
            $confirmedId = $record['typed']['value']['entity_ref']['id'] ?? null;
            $confirmed = is_string($confirmedId) ? ($recordsBySource[$confirmedId] ?? null) : null;
            if (($confirmed['kind'] ?? null) === 'entity') {
                $values[] = $confirmed['data']['value'] ?? null;
                $values = [...$values, ...($confirmed['data']['aliases'] ?? [])];
            }
            foreach ($values as $value) {
                if (is_string($value)) {
                    $known[] = $this->normalizer->normalize($value);
                }
            }
        }
        foreach ($entities as $entity) {
            if (! in_array($this->normalizer->normalize($entity), $known, true)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function unitTokens(string $text): array
    {
        preg_match_all('/\b[A-Z]{3}\s+\d|\b\d[\d,.]*\s*(?:%\B|percent\b)/u', $text, $matches);

        return $matches[0];
    }

    /** @param list<string> $tokens @param list<array<string,mixed>> $records */
    private function unitsConsistent(array $tokens, array $numbers, array $records, array $block): bool
    {
        foreach ($tokens as $token) {
            $found = false;
            foreach ($numbers as $number) {
                if (str_contains($number['raw'], $token)
                    && $this->numbersGrounded([$number], $records, $block)) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                return false;
            }
        }

        return true;
    }

    private function comparisonDirection(string $text): ?int
    {
        if (preg_match('/\b(?:rose|risen|increased|higher than|up from)\b/iu', $text)) {
            return 1;
        }
        if (preg_match('/\b(?:fell|decreased|lower than|down from)\b/iu', $text)) {
            return -1;
        }

        return null;
    }

    /** @param list<array<string,mixed>> $records */
    private function comparisonValid(int $direction, array $records): bool
    {
        for ($i = 0; $i < count($records); $i++) {
            for ($j = $i + 1; $j < count($records); $j++) {
                $a = $records[$i]['typed']['value'] ?? null;
                $b = $records[$j]['typed']['value'] ?? null;
                if (is_array($a) && is_array($b) && is_numeric($a['number'] ?? null)
                    && is_numeric($b['number'] ?? null) && $this->comparable($a, $b)
                    && (($b['number'] <=> $a['number']) === $direction)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function comparable(array $a, array $b): bool
    {
        return ($a['type'] ?? null) === ($b['type'] ?? null)
            && ($a['unit_kind'] ?? null) === ($b['unit_kind'] ?? null)
            && ($a['currency'] ?? null) === ($b['currency'] ?? null)
            && (($a['type'] ?? null) !== 'percent' || ($a['basis'] ?? null) === ($b['basis'] ?? null));
    }

    private function namesAttribution(string $text, array $attribution): bool
    {
        $speaker = $attribution['speaker'] ?? null;
        $role = $attribution['role'] ?? null;

        return (is_string($speaker) && $speaker !== '' && str_contains(mb_strtolower($text), mb_strtolower($speaker)))
            || (is_string($role) && $role !== '' && preg_match('/\b'.preg_quote($role, '/').'\b/iu', $text));
    }

    /** @param list<array<string,mixed>> $records */
    private function leaksQuote(string $text, array $records): bool
    {
        $max = config('intelligence_v2.brief.max_quote_chars');
        foreach ($records as $record) {
            foreach ($record['sources'] ?? [] as $source) {
                $quote = $source['quote'] ?? null;
                if (! is_string($quote) || mb_strlen($quote) <= $max) {
                    continue;
                }
                for ($offset = 0; $offset + $max < mb_strlen($quote); $offset++) {
                    if (str_contains($text, mb_substr($quote, $offset, $max + 1))) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
