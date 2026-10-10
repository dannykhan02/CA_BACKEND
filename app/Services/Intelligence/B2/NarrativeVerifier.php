<?php

namespace App\Services\Intelligence\B2;

use App\Services\Intelligence\Brief\BriefVerifier;

/**
 * Local verification of a generated narrative against the records it cites. No provider request and
 * no persistence; the caller decides what to do with the verdict.
 *
 * Most of the grounding work is BriefVerifier's, which already decides whether prose is supported by
 * stored Stage A records — numbers, dates, periods, entities, units, comparison direction, negative
 * claims, attribution, origin/assertion legality and source-text leakage. Each generated claim is
 * verified as a `docintel_ai` / `stated` block, which is the strictest combination that verifier
 * offers: `stated` additionally requires that no cited record has unknown provenance.
 *
 * Added here are the checks that are specific to a free-text narrative rather than a template:
 * output shape, claim count and length, renderable plain text, citation supply, and per-number
 * binding to cited canonical values.
 *
 * Fail closed. A claim whose verdict is not `passed` is rejected, and by default (max_rejected_claims
 * = 0) one rejected claim rejects the whole narrative, so a partly-hallucinated brief never reaches
 * a reader and B1 is served instead.
 */
class NarrativeVerifier
{
    /** Markdown, link and markup characters a claim may never contain, so the text is safe to render. */
    private const UNSAFE = ['`', '*', '_', '#', '[', ']', '{', '}', '<', '>', '|', '~', 'http://', 'https://', 'www.'];

    public function __construct(private BriefVerifier $briefVerifier,
        private NumericClaimGrounder $numericGrounder) {}

    /**
     * @param  array<string,mixed>  $decoded  the parsed provider response
     * @param  list<string>  $supplied  source ids actually sent in the B2 context
     * @param  array<string,array<string,mixed>>  $records  Stage A records keyed by source id, restricted to $supplied
     * @param  list<string>  $keyFigureIds  B1 display selection; retained for caller compatibility
     * @return array{status:string,reasons:list<string>,claims:list<array<string,mixed>>,
     *               rejected:list<array<string,mixed>>,verifier_version:string}
     */
    public function verify(array $decoded, array $supplied, array $records, array $keyFigureIds): array
    {
        $settings = config('intelligence_v2.b2');
        $version = (string) $settings['verifier_version'];
        $fail = static fn (string $reason) => ['status' => 'rejected', 'reasons' => [$reason],
            'claims' => [], 'rejected' => [], 'verifier_version' => $version];

        $narrative = $decoded['narrative'] ?? null;
        if (! is_array($narrative) || ! array_is_list($narrative)) {
            return $fail('malformed_output');
        }
        if (count($narrative) < (int) $settings['min_claims']) {
            return $fail('insufficient_claims');
        }
        if (count($narrative) > (int) $settings['max_claims']) {
            return $fail('too_many_claims');
        }

        $available = array_fill_keys($supplied, true);
        $accepted = [];
        $rejected = [];
        $reasons = [];
        $seen = [];

        foreach ($narrative as $index => $item) {
            $verdict = $this->claim($item, $available, $records, $seen, $settings);
            if ($verdict['reasons'] === []) {
                $accepted[] = ['text' => $verdict['text'], 'cites' => $verdict['cites']];
                $seen[$verdict['normalized']] = true;

                continue;
            }
            $rejected[] = ['index' => $index, 'reasons' => $verdict['reasons'],
                'cites' => $verdict['cites']];
            $reasons = [...$reasons, ...$verdict['reasons']];
        }

        $reasons = array_values(array_unique($reasons));
        if (count($rejected) > (int) $settings['max_rejected_claims']) {
            return ['status' => 'rejected', 'reasons' => $reasons, 'claims' => [],
                'rejected' => $rejected, 'verifier_version' => $version];
        }
        if (count($accepted) < (int) $settings['min_claims']) {
            return ['status' => 'rejected', 'reasons' => [...$reasons, 'insufficient_claims'],
                'claims' => [], 'rejected' => $rejected, 'verifier_version' => $version];
        }

        return ['status' => 'verified', 'reasons' => $reasons, 'claims' => $accepted,
            'rejected' => $rejected, 'verifier_version' => $version];
    }

    /**
     * @param  array<string,bool>  $available
     * @param  array<string,array<string,mixed>>  $records
     * @param  array<string,bool>  $seen
     * @param  array<string,mixed>  $settings
     * @return array{text:string,cites:list<string>,normalized:string,reasons:list<string>}
     */
    private function claim(mixed $item, array $available, array $records,
        array $seen, array $settings): array
    {
        $reasons = [];
        $text = is_array($item) && is_string($item['claim'] ?? null) ? trim($item['claim']) : '';
        $rawCites = is_array($item) && is_array($item['cites'] ?? null) ? $item['cites'] : null;
        $cites = [];
        if ($rawCites === null || ! array_is_list($rawCites)) {
            $reasons[] = 'malformed_output';
        } else {
            foreach ($rawCites as $id) {
                if (! is_string($id) || trim($id) === '') {
                    $reasons[] = 'malformed_output';

                    continue;
                }
                $cites[] = $id;
            }
        }
        $cites = array_values(array_unique($cites));
        $normalized = mb_strtolower(preg_replace('/\s+/u', ' ', $text) ?? '');

        if ($text === '') {
            $reasons[] = 'malformed_output';

            return ['text' => $text, 'cites' => $cites, 'normalized' => $normalized,
                'reasons' => array_values(array_unique($reasons))];
        }

        // Renderable plain text. The narrative is returned to the API as text, so markup, links and
        // control characters are rejected rather than escaped downstream.
        if (mb_strlen($text) < (int) $settings['min_claim_chars']
            || mb_strlen($text) > (int) $settings['max_claim_chars']) {
            $reasons[] = 'claim_length';
        }
        if (preg_match('/[\p{Cc}\p{Cf}]/u', $text) === 1) {
            $reasons[] = 'unsafe_text';
        }
        foreach (self::UNSAFE as $token) {
            if (stripos($text, $token) !== false) {
                $reasons[] = 'unsafe_text';
                break;
            }
        }
        if (isset($seen[$normalized])) {
            $reasons[] = 'duplicate_claim';
        }

        if ($cites === []) {
            $reasons[] = 'uncited_claim';
        }
        if (count($cites) > (int) $settings['max_cites_per_claim']) {
            $reasons[] = 'too_many_cites';
        }
        foreach ($cites as $id) {
            if (! isset($available[$id])) {
                // Either an id that exists nowhere, or one that exists but was not supplied to B2.
                $reasons[] = isset($records[$id]) ? 'citation_not_supplied' : 'fabricated_citation';

                continue;
            }
            if (($records[$id]['provenance']['origin'] ?? null) !== 'document') {
                $reasons[] = 'untrusted_support';
            }
        }

        $cited = array_values(array_filter(array_map(
            static fn (string $id) => $records[$id] ?? null, $cites)));

        if ($cited !== []) {
            $reasons = [...$reasons, ...$this->grounding($text, $cites, $cited, $records, array_keys($available))];
            if (! $this->numericGrounder->grounded($text, $cited)) {
                $reasons[] = 'numeric_fact_grounded';
            }
        }

        return ['text' => $text, 'cites' => $cites, 'normalized' => $normalized,
            'reasons' => array_values(array_unique($reasons))];
    }

    /**
     * Reuses B1's hardened grounding checks on the generated sentence.
     *
     * @param  list<string>  $cites
     * @param  list<array<string,mixed>>  $cited
     * @param  array<string,array<string,mixed>>  $records
     * @param  list<string>  $available
     * @return list<string>
     */
    private function grounding(string $text, array $cites, array $cited, array $records, array $available): array
    {
        // A claim may name a party using the wording in a cited record's own label, subject or
        // entity value. BriefVerifier reads confirmed_entity_names as exactly this kind of
        // record-local allowance, so nothing in B1 has to change to express it.
        $prepared = [];
        foreach ($records as $id => $record) {
            $record['confirmed_entity_names'] = $this->names($record);
            $prepared[$id] = $record;
        }

        // The strictest legal combination for generated prose: `stated` makes BriefVerifier reject
        // the block if any cited record has unknown provenance.
        $attribution = ['speaker' => null, 'role' => 'unattributed', 'reported' => false, 'evidence_ref' => null];
        foreach ($cited as $record) {
            if ($record['provenance']['attribution']['reported'] ?? false) {
                $attribution = $record['provenance']['attribution'];
                break;
            }
        }
        $block = ['type' => 'finding', 'text' => $text, 'detail' => null,
            'origin' => 'docintel_ai', 'assertion' => 'stated', 'cites' => $cites,
            'attribution' => $attribution, 'template_id' => null, 'absence_check' => null];

        $verification = $this->briefVerifier->verify($block, $prepared, $available);

        return array_values(array_map(static fn (string $check) => 'brief_'.$check,
            $verification['failed_reasons']));
    }

    /** @param array<string,mixed> $record @return list<string> */
    private function names(array $record): array
    {
        $data = is_array($record['data'] ?? null) ? $record['data'] : [];
        $names = [$data['label'] ?? null, $data['subject'] ?? null];
        if (($record['kind'] ?? null) === 'entity') {
            $names[] = $data['value'] ?? null;
            $names = [...$names, ...(is_array($data['aliases'] ?? null) ? $data['aliases'] : [])];
        }

        return array_values(array_filter($names, 'is_string'));
    }
}
