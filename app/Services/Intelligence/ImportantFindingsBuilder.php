<?php

namespace App\Services\Intelligence;

use App\Models\DocumentEvidence;
use App\Models\DocumentIntelligenceSummary;
use Illuminate\Support\Collection;

/**
 * High-value findings that belong neither in a chart nor in a takeaway, ranked with the mechanisms
 * the repository already uses rather than a new scoring scheme.
 *
 * The order uses signals that are already recorded: first whether the document's own synthesis
 * cited the finding, which is the repository's existing statement that it mattered; then the
 * extraction confidence stored on the finding; then EvidenceBudget's own materiality tiers, the
 * same priority that decides which evidence reaches synthesis at all.
 *
 * At most two findings may share a label stem. A rich report's accepted evidence usually ends in a
 * long run of equally confident table rows ("Line item 80", "Line item 81", ...); without this the
 * section would fill with one table instead of showing the reader eight different things.
 *
 * Risks, obligations and deadlines are excluded: they have their own sections. So is anything a
 * chart or takeaway already states, so this section adds to the page instead of repeating it. The
 * analysis groups are not excluded - those are a browse-by-category view of everything, and a
 * highlight the reader can also find by browsing is still the highlight.
 */
class ImportantFindingsBuilder
{
    private const MAX = 8;

    private const MAX_PER_STEM = 2;

    /** EvidenceBudget::forDocument()'s materiality tiers, reused so one ranking governs both. */
    private const TIERS = ['metric' => 1, 'definition' => 3, 'entity' => 3];

    /**
     * @param  Collection<int,DocumentEvidence>  $evidence
     * @param  list<string>  $alreadyShown
     * @return list<array<string,mixed>>
     */
    public function build(Collection $evidence, ?DocumentIntelligenceSummary $summary, array $alreadyShown): array
    {
        $cited = array_fill_keys($this->citedSourceIds($summary), true);
        $shown = array_fill_keys($alreadyShown, true);

        $candidates = [];
        foreach ($evidence as $row) {
            $reference = $row->source_id ?: 'evidence:'.$row->id;
            if (isset($shown[$reference]) || in_array($row->kind, ['risk', 'deadline', 'obligation', 'unresolved'], true)) {
                continue;
            }
            $data = is_array($row->data) ? $row->data : [];
            if (trim((string) ($data['value'] ?? '')) === '' && trim((string) ($data['label'] ?? '')) === '') {
                continue;
            }
            $candidates[] = [
                'tier' => self::TIERS[$row->kind] ?? 4,
                'cited' => isset($cited[$reference]),
                'confidence' => round((float) ($data['confidence'] ?? 0), 2),
                'row' => $row,
                'reference' => $reference,
            ];
        }

        usort($candidates, fn ($a, $b) => [$b['cited'], $b['confidence'], $a['tier'], $a['reference']]
            <=> [$a['cited'], $a['confidence'], $b['tier'], $b['reference']]);

        $findings = [];
        $stems = [];
        foreach ($candidates as $candidate) {
            if (count($findings) >= self::MAX) {
                break;
            }
            $row = $candidate['row'];
            $stem = $this->stem((string) ($row->data['label'] ?? ''));
            if (($stems[$stem] ?? 0) >= self::MAX_PER_STEM) {
                continue;
            }
            $stems[$stem] = ($stems[$stem] ?? 0) + 1;
            $data = is_array($row->data) ? $row->data : [];
            $pages = array_values(array_unique(array_filter(array_column($row->sources ?? [], 'page'), fn ($page) => $page !== null)));
            $findings[] = [
                'sourceId' => $candidate['reference'],
                'kind' => $row->kind,
                'label' => (string) ($data['label'] ?? ''),
                'value' => (string) ($data['value'] ?? ''),
                'unit' => $data['unit'] ?? null,
                'period' => $data['period'] ?? null,
                'subject' => (string) ($data['subject'] ?? ''),
                'confidence' => $candidate['confidence'],
                'citedBySynthesis' => $candidate['cited'],
                'page' => count($pages) === 1 ? (int) reset($pages) : null,
            ];
        }

        return $findings;
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
