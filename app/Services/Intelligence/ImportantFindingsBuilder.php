<?php

namespace App\Services\Intelligence;

use App\Models\DocumentEvidence;
use App\Models\DocumentIntelligenceSummary;
use App\Services\Intelligence\Materiality\MaterialityScorer;
use Illuminate\Support\Collection;

/**
 * High-value findings that belong neither in a chart nor in a takeaway, ranked by how much a
 * reader is likely to need them rather than by how sure the extractor was about them.
 *
 * Extraction confidence is a poor headline signal: a row-level table figure is read with near
 * certainty, which used to let routine metrics outrank a critical risk or an obligation falling
 * due. So the order is decided first by a small table of usefulness tiers, built only from
 * information the finding already carries - its kind, a risk's severity, whether an obligation
 * has a real date and whether that date is still ahead. Confidence is only a tie-break inside a
 * tier.
 *
 * One deliberate adjustment sits on top: a finding the document's own synthesis cited moves up a
 * single tier. That is the repository's existing statement that the finding mattered, and one
 * tier is enough to let it overtake its neighbours without letting it jump the whole table.
 *
 * Two caps keep the section varied rather than letting one table or one kind fill it. Anything a
 * chart or takeaway already states is excluded, so the most consequential risks and obligations -
 * the ones promoted into takeaways - are not repeated here.
 */
class ImportantFindingsBuilder
{
    private const MAX = 8;

    /** A rich report ends in long runs of near-identical table rows; two of any one is plenty. */
    private const MAX_PER_STEM = 2;

    /** No single kind of finding may fill the section. */
    private const MAX_PER_KIND = 3;

    /**
     * Usefulness tiers, lowest first. Readable on purpose: every entry is a plain statement about
     * what kind of finding it is, and there is no arithmetic beyond the single-tier promotion for
     * a synthesis citation.
     */
    private const TIERS = [
        'critical_risk' => 0,
        'high_risk' => 1,
        'upcoming_obligation' => 1,
        'dated_obligation' => 2,
        'undated_obligation' => 3,
        'risk' => 3,
        'metric' => 4,
        'fact' => 4,
        'definition' => 5,
        'entity' => 5,
        'other' => 6,
    ];

    /**
     * @param  Collection<int,DocumentEvidence>  $evidence
     * @param  list<string>  $alreadyShown
     * @return list<array<string,mixed>>
     */
    public function build(Collection $evidence, ?DocumentIntelligenceSummary $summary, array $alreadyShown,
        ?array $materiality = null): array
    {
        if ($materiality !== null && config('intelligence_v2.enabled')) {
            return $this->buildV2($evidence, $summary, $alreadyShown, $materiality);
        }
        $cited = array_fill_keys($this->citedSourceIds($summary), true);
        $shown = array_fill_keys($alreadyShown, true);
        $today = now()->startOfDay();

        $candidates = [];
        foreach ($evidence as $row) {
            $reference = $row->source_id ?: 'evidence:'.$row->id;
            // `unresolved` findings are an internal extraction state, never a reader's finding.
            if (isset($shown[$reference]) || $row->kind === 'unresolved') {
                continue;
            }
            $data = is_array($row->data) ? $row->data : [];
            if (trim((string) ($data['value'] ?? '')) === '' && trim((string) ($data['label'] ?? '')) === '') {
                continue;
            }
            $isCited = isset($cited[$reference]);
            $candidates[] = [
                'tier' => max(0, self::TIERS[$this->classify($row->kind, $data, $today)] - ($isCited ? 1 : 0)),
                'confidence' => round((float) ($data['confidence'] ?? 0), 2),
                'row' => $row,
                'data' => $data,
                'cited' => $isCited,
                'reference' => $reference,
            ];
        }

        usort($candidates, fn ($a, $b) => [$a['tier'], $b['confidence'], $a['reference']]
            <=> [$b['tier'], $a['confidence'], $b['reference']]);

        $findings = [];
        $stems = [];
        $kinds = [];
        foreach ($candidates as $candidate) {
            if (count($findings) >= self::MAX) {
                break;
            }
            $data = $candidate['data'];
            $stem = $this->stem((string) ($data['label'] ?? ''));
            $kind = $this->group($candidate['row']->kind);
            if (($stems[$stem] ?? 0) >= self::MAX_PER_STEM || ($kinds[$kind] ?? 0) >= self::MAX_PER_KIND) {
                continue;
            }
            $stems[$stem] = ($stems[$stem] ?? 0) + 1;
            $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
            $pages = array_values(array_unique(array_filter(array_column($candidate['row']->sources ?? [], 'page'),
                fn ($page) => $page !== null)));
            $findings[] = [
                'sourceId' => $candidate['reference'],
                'kind' => $candidate['row']->kind,
                'label' => (string) ($data['label'] ?? ''),
                'value' => (string) ($data['value'] ?? ''),
                'unit' => $data['unit'] ?? null,
                'period' => $data['period'] ?? null,
                'subject' => (string) ($data['subject'] ?? ''),
                'severity' => $this->severity($data),
                'dueDate' => $this->dueDate($data),
                'confidence' => $candidate['confidence'],
                'citedBySynthesis' => $candidate['cited'],
                'page' => count($pages) === 1 ? (int) reset($pages) : null,
            ];
        }

        return $findings;
    }

    /** @param Collection<int,DocumentEvidence> $evidence @param list<string> $alreadyShown @param array<string,array<string,mixed>> $materiality @return list<array<string,mixed>> */
    private function buildV2(Collection $evidence, ?DocumentIntelligenceSummary $summary, array $alreadyShown,
        array $materiality): array
    {
        $shown = array_fill_keys($alreadyShown, true);
        $cited = array_fill_keys($this->citedSourceIds($summary), true);
        $candidates = [];
        foreach ($evidence as $row) {
            $reference = $row->source_id ?: 'evidence:'.$row->id;
            $data = is_array($row->data) ? $row->data : [];
            if (isset($shown[$reference]) || $row->kind === 'unresolved'
                || (trim((string) ($data['value'] ?? '')) === '' && trim((string) ($data['label'] ?? '')) === '')) {
                continue;
            }
            if (isset($materiality[$row->identity])) {
                $candidates[] = ['row' => $row, 'data' => $data, 'reference' => $reference,
                    'assignment' => $materiality[$row->identity]];
            }
        }
        usort($candidates, static function ($a, $b) {
            $score = $b['assignment']['score'] <=> $a['assignment']['score'];
            if ($score !== 0) {
                return $score;
            }
            $record = static fn ($candidate) => ['identity' => $candidate['row']->identity,
                'kind' => $candidate['row']->kind, 'data' => $candidate['data'],
                'sources' => $candidate['row']->sources ?? []];

            return MaterialityScorer::compareTiebreak($record($a), $record($b), $a['assignment'], $b['assignment']);
        });
        $findings = [];
        $stems = [];
        $kinds = [];
        foreach ($candidates as $candidate) {
            if (count($findings) >= config('intelligence_v2.tier1.target')) {
                break;
            }
            $data = $candidate['data'];
            $row = $candidate['row'];
            $forced = $candidate['assignment']['forced'];
            $stem = $this->stem((string) ($data['label'] ?? ''));
            $kind = $this->group($row->kind);
            if (! $forced && (($stems[$stem] ?? 0) >= config('intelligence_v2.tier1.per_stem')
                || ($kinds[$kind] ?? 0) >= config('intelligence_v2.tier1.per_kind'))) {
                continue;
            }
            $stems[$stem] = ($stems[$stem] ?? 0) + 1;
            $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
            $pages = array_values(array_unique(array_filter(array_column($row->sources ?? [], 'page'),
                fn ($page) => $page !== null)));
            $findings[] = [
                'sourceId' => $candidate['reference'], 'kind' => $row->kind,
                'label' => (string) ($data['label'] ?? ''), 'value' => (string) ($data['value'] ?? ''),
                'unit' => $data['unit'] ?? null, 'period' => $data['period'] ?? null,
                'subject' => (string) ($data['subject'] ?? ''), 'severity' => $this->severity($data),
                'dueDate' => $this->dueDate($data),
                'confidence' => round((float) ($data['confidence'] ?? 0), 2),
                'citedBySynthesis' => isset($cited[$candidate['reference']]),
                'page' => count($pages) === 1 ? (int) reset($pages) : null,
                'materialityTier' => $candidate['assignment']['tier'],
                'forced' => $forced, 'forcedRule' => $candidate['assignment']['forced_rule'],
                'tierReasons' => $candidate['assignment']['reasons'],
            ];
        }

        return $findings;
    }

    /**
     * Which usefulness tier this finding falls in. Only facts the finding states: nothing is
     * guessed from wording, and an obligation without a real date is never treated as dated.
     *
     * @param  array<string,mixed>  $data
     */
    private function classify(string $kind, array $data, \DateTimeInterface $today): string
    {
        if ($kind === 'risk') {
            return match ($this->severity($data)) {
                'critical' => 'critical_risk',
                'high' => 'high_risk',
                default => 'risk',
            };
        }
        if ($kind === 'deadline' || $kind === 'obligation') {
            $due = $this->dueDate($data);
            if ($due === null) {
                // Relative or inferred timing is still an obligation; it just has no calendar date.
                return 'undated_obligation';
            }

            return $due >= $today->format('Y-m-d') ? 'upcoming_obligation' : 'dated_obligation';
        }

        return array_key_exists($kind, self::TIERS) ? $kind : 'other';
    }

    /** @param array<string,mixed> $data */
    private function severity(array $data): ?string
    {
        return SeverityNormalizer::normalize($data['severity'] ?? null);
    }

    /** An explicit calendar date only, exactly as the extraction schema guarantees it. */
    private function dueDate(array $data): ?string
    {
        $due = is_string($data['due_date'] ?? null) ? trim($data['due_date']) : '';

        return ($data['date_type'] ?? null) === 'explicit' && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $due) ? $due : null;
    }

    /** Obligations and deadlines are one kind for the diversity cap: they read the same. */
    private function group(string $kind): string
    {
        return $kind === 'obligation' ? 'deadline' : $kind;
    }

    /** A label with its numbering removed, so the rows of one table share a stem. */
    private function stem(string $label): string
    {
        $stem = preg_replace('/[\p{N}]+/u', '', mb_strtolower($label));

        return trim((string) preg_replace('/[^\p{L}\s]+|\s+/u', ' ', (string) $stem));
    }

    /** @return list<string> */
    public function citedSourceIds(?DocumentIntelligenceSummary $summary): array
    {
        if (! $summary) {
            return [];
        }
        $ids = [];
        $sections = [$summary->material_findings ?? [], $summary->trends ?? [],
            $summary->tensions ?? [], $summary->questions ?? []];
        if (is_array($summary->executive_assessment)) {
            $sections[] = [$summary->executive_assessment];
        }
        foreach ($sections as $section) {
            foreach ((array) $section as $item) {
                foreach ((array) (is_array($item) ? ($item['source_ids'] ?? []) : []) as $id) {
                    if (is_string($id)) {
                        $ids[$id] = true;
                    }
                }
            }
        }

        return array_keys($ids);
    }
}
