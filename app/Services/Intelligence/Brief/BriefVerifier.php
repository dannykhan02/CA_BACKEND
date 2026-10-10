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
    /** Lowercase particles a single proper name may contain: "Government of India". */
    private const ENTITY_PARTICLES = ['of', 'de', 'del', 'della', 'da', 'dos', 'van', 'von',
        'der', 'den', 'bin', 'al'];

    /**
     * Grammatical wrappers a sentence can put in front of a name without them becoming part of it.
     * Only ever removed from the front, and only one of them, so "The Hague" keeps its article.
     */
    private const ENTITY_WRAPPERS = ['the', 'a', 'an', 'in', 'on', 'at', 'by', 'for', 'from', 'to',
        'of', 'with', 'within', 'during', 'under', 'over', 'across', 'into', 'after', 'before',
        'since', 'between', 'through', 'per', 'and', 'but', 'as', 'that', 'this', 'these', 'those'];

    /** Units a time span can be written in, mapped to the stem a span is compared on. */
    private const DURATION_UNITS = ['second' => 'second', 'seconds' => 'second',
        'minute' => 'minute', 'minutes' => 'minute', 'hour' => 'hour', 'hours' => 'hour',
        'day' => 'day', 'days' => 'day', 'week' => 'week', 'weeks' => 'week',
        'month' => 'month', 'months' => 'month', 'quarter' => 'quarter', 'quarters' => 'quarter',
        'year' => 'year', 'years' => 'year', 'decade' => 'decade', 'decades' => 'decade'];

    /** Spelled-out counts a duration can use. */
    private const DURATION_WORDS = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
        'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10, 'eleven' => 11,
        'twelve' => 12];

    /** Words that mark what follows as a time span rather than a quantity. */
    private const DURATION_QUALIFIERS = ['over', 'within', 'for', 'in', 'during', 'across',
        'after', 'before', 'past', 'last', 'next', 'previous', 'every', 'per', 'of'];

    /** Determiners and fillers that may sit between the qualifier and the count. */
    private const DURATION_FILLERS = ['the', 'a', 'an', 'course', 'past', 'last', 'next',
        'previous', 'coming', 'recent', 'first', 'final'];

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
        $durations = $this->durations($text);
        $add('durations_grounded', $durations === [] ? null
            : $this->durationsGrounded($durations, $records));
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
        // A duration is a time span, not a quantity: "over the last 10 years" must not leave the
        // number 10 behind for numbersGrounded() to hunt for in a typed value. Spans are checked
        // as spans by durationsGrounded() instead.
        foreach ([...$this->dates($text), ...$this->periods($text), ...$this->durations($text)]
            as $temporal) {
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
                // The claim's own code is compared against the code this record owns, so a claim
                // written "USD 97.4 million" grounds on a record that states USD in either field
                // and never on one that states CAD.
                if ($number['unit'] !== null && $number['unit'] !== $this->currencyKey($value)
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
            if ($this->recordCurrency($value) === null) {
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

    /** Whether one stored typed value is complete enough to ground a numeric claim. Read-only. */
    public function groundableValue(mixed $value): bool
    {
        return $this->validNumericValue($value);
    }

    /**
     * The ISO currency code a stored typed value owns, read the same way grounding reads it.
     * Read-only, and exposed so B2's money gate and the production diagnostic share one answer.
     *
     * @param  array<string,mixed>  $value
     */
    public function recordCurrencyCode(array $value): ?string
    {
        return $this->recordCurrency($value);
    }

    /**
     * The ISO currency code the cited record itself owns, or null.
     *
     * The values parser stores what the document wrote beside the number in `currency` and the unit
     * the record was extracted with in `unit`, so a figure written "$97.4 million" with unit "USD"
     * carries the symbol in one field and the code in the other. Both fields belong to this one
     * record, so reading the code off `unit` keeps monetary grounding record-local: nothing is
     * borrowed from a neighbouring record or from the document text.
     *
     * A bare "$" is completed from nowhere else. "$" is not provably USD - it is as much CAD or AUD
     * - so a record whose own fields state no code cannot ground a monetary claim at all.
     */
    private function recordCurrency(array $value): ?string
    {
        $currency = $value['currency'] ?? null;
        if (is_string($currency) && preg_match('/^[A-Z]{3}$/D', $currency)) {
            return $currency;
        }
        if ($currency !== '$' || ! is_string($value['unit'] ?? null)) {
            return null;
        }

        return $this->measurements->isoCurrency($value['unit']);
    }

    /**
     * The currency identity a typed value is compared on. Resolved for money, so two records that
     * both wrote "$" are not treated as one currency when their units say USD and CAD; an
     * unresolvable money value keeps its stored marker rather than collapsing onto null.
     */
    private function currencyKey(array $value): ?string
    {
        return ($value['type'] ?? null) === 'money'
            ? ($this->recordCurrency($value) ?? ($value['currency'] ?? null))
            : ($value['currency'] ?? null);
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

    /**
     * Capitalised runs a sentence names, with the lowercase particles a single name may contain
     * read as part of it, so "Government of India" is one mention rather than "The Government" and
     * a dropped "India". Conjunctions are deliberately not particles: they would weld two separate
     * names into one mention.
     *
     * @return list<string>
     */
    private function entities(string $text): array
    {
        $word = '\p{Lu}[\p{L}\p{M}]+';
        $particle = '(?:'.implode('|', self::ENTITY_PARTICLES).')';
        preg_match_all('/\b'.$word.'(?:\s+(?:'.$particle.'\s+)?'.$word.')+\b/u', $text, $matches);

        return $matches[0];
    }

    /**
     * The normalized forms one mention may be compared in: the mention itself, and the mention with
     * a single leading grammatical wrapper removed, so a sentence-initial "In India" can be read as
     * the name "India".
     *
     * Nothing is added to a name and nothing is taken from another record. "Government" never
     * becomes "Government of India"; only the words the sentence put in front of the name are
     * removed, and the unstripped form is kept as a candidate so a record whose own subject begins
     * with an article still matches.
     *
     * @return list<string>
     */
    private function entitySurfaces(string $entity): array
    {
        $surfaces = [$this->normalizer->normalize($entity)];
        $words = preg_split('/\s+/u', trim($entity)) ?: [];
        if (count($words) > 1 && in_array(mb_strtolower($words[0]), self::ENTITY_WRAPPERS, true)) {
            $surfaces[] = $this->normalizer->normalize(implode(' ', array_slice($words, 1)));
        }

        return array_values(array_filter(array_unique($surfaces), static fn ($s) => $s !== ''));
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
            if (array_intersect($this->entitySurfaces($entity), $known) === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * Time spans the prose states: a qualifier, then a count, then a duration unit. The count is
     * required, which is what keeps a calendar phrase like "for the year ended 2024" out of the
     * duration path - that is a period, and periodsGrounded() already owns it.
     *
     * @return list<string>
     */
    private function durations(string $text): array
    {
        $qualifier = '(?:'.implode('|', self::DURATION_QUALIFIERS).')';
        $filler = '(?:'.implode('|', [...self::DURATION_FILLERS, ...self::DURATION_QUALIFIERS]).')';
        $count = '(?:\d[\d,]*|'.implode('|', array_keys(self::DURATION_WORDS)).')';
        $unit = '(?:'.implode('|', array_keys(self::DURATION_UNITS)).')';
        preg_match_all('/\b'.$qualifier.'(?:\s+'.$filler.'){0,6}\s+'.$count.'\s+'.$unit.'\b/iu',
            $text, $matches);

        return $matches[0];
    }

    /**
     * A duration expression reduced to the span it states, as "<count> <unit>", or null when it
     * states no span. Spelled-out counts become digits and units are stemmed, so "five years" and
     * "5 years" are the same span written twice and neither is the span "5 months".
     */
    private function durationSpan(string $text): ?string
    {
        $count = '(\d[\d,]*|'.implode('|', array_keys(self::DURATION_WORDS)).')';
        $unit = '('.implode('|', array_keys(self::DURATION_UNITS)).')';
        if (! preg_match('/\b'.$count.'\s+'.$unit.'\b/iu', $text, $parts)) {
            return null;
        }
        $written = mb_strtolower($parts[1]);
        $number = self::DURATION_WORDS[$written] ?? (float) str_replace(',', '', $written);

        return sprintf('%.4F %s', (float) $number, self::DURATION_UNITS[mb_strtolower($parts[2])]);
    }

    /**
     * The time spans one record states itself: the period it was extracted with, when that period
     * is a span and the record's own quote states it, and any typed relative duration. Both sources
     * belong to this record; a span is never read off a neighbour.
     *
     * @param  array<string,mixed>  $record
     * @return list<string>
     */
    private function recordDurations(array $record): array
    {
        $spans = [];
        $period = $record['data']['period'] ?? null;
        if (is_string($period) && ($span = $this->durationSpan($period)) !== null
            && $this->statedInSources($period, $record)) {
            $spans[] = $span;
        }
        foreach ($record['typed']['dates'] ?? [] as $date) {
            $text = $date['duration']['text'] ?? null;
            if (($date['type'] ?? null) === 'duration' && ($date['resolution'] ?? null) === 'relative'
                && ($date['duration']['anchor_resolved'] ?? null) === false
                && is_string($text) && ($span = $this->durationSpan($text)) !== null) {
                $spans[] = $span;
            }
        }

        return array_values(array_unique($spans));
    }

    /** @param list<string> $durations @param list<array<string,mixed>> $records */
    private function durationsGrounded(array $durations, array $records): bool
    {
        $known = [];
        foreach ($records as $record) {
            foreach ($this->recordDurations($record) as $span) {
                $known[$span] = true;
            }
        }
        foreach ($durations as $duration) {
            $span = $this->durationSpan($duration);
            if ($span === null || ! isset($known[$span])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether one of the record's own cited quotes states this text, ignoring how the source wrapped
     * its lines. Scoped to the record passed in, so this stays a record-local check.
     *
     * @param  array<string,mixed>  $record
     */
    private function statedInSources(string $text, array $record): bool
    {
        $needle = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($needle === '') {
            return false;
        }
        foreach ($record['sources'] ?? [] as $source) {
            $quote = $source['quote'] ?? null;
            if (is_string($quote)
                && str_contains(trim((string) preg_replace('/\s+/u', ' ', $quote)), $needle)) {
                return true;
            }
        }

        return false;
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
            && $this->currencyKey($a) === $this->currencyKey($b)
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
