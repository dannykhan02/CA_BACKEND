<?php

namespace App\Services\Intelligence;

use App\Models\DocumentEvidence;
use Illuminate\Support\Collection;

/**
 * Groups accepted evidence into the sections a reader can actually use, derived from DocIntel's
 * own finding taxonomy rather than a fixed set of tabs: the finding `kind`, the `entity_type`
 * recorded for entities, and - for metrics - the measurement kind MeasurementParser reads from the
 * value itself.
 *
 * Risks, obligations and deadlines are deliberately absent: they already have dedicated sections
 * with their own review, tracking and source controls, and repeating them here would duplicate
 * both the data and the interaction.
 *
 * A group that has no findings is never emitted, so the sections a document shows describe that
 * document.
 */
class AnalysisGrouper
{
    /** Inline items per group. The rest stay reachable through the existing paginated endpoints. */
    private const PREVIEW = 6;

    private const LABELS = [
        'financial' => 'Financial figures',
        'shares' => 'Shares & ratios',
        'operational' => 'Operational figures',
        'organizations' => 'Organizations',
        'people' => 'People',
        'governance' => 'Governance & regulators',
        'geography' => 'Geography',
        'references' => 'References & identifiers',
        'dates' => 'Dates mentioned',
        'definitions' => 'Definitions',
        'context' => 'Context & facts',
    ];

    public function __construct(private MeasurementParser $measurements) {}

    /**
     * @param  Collection<int,DocumentEvidence>  $evidence
     * @return list<array<string,mixed>>
     */
    public function build(Collection $evidence): array
    {
        $buckets = [];
        foreach ($evidence as $row) {
            $group = $this->groupFor($row);
            if ($group !== null) {
                $buckets[$group][] = $row;
            }
        }

        $groups = [];
        foreach (self::LABELS as $key => $label) {
            $rows = $buckets[$key] ?? [];
            if ($rows === []) {
                continue;
            }
            usort($rows, fn ($a, $b) => [(float) ($b->data['confidence'] ?? 0), (string) ($a->data['label'] ?? '')]
                <=> [(float) ($a->data['confidence'] ?? 0), (string) ($b->data['label'] ?? '')]);
            $groups[] = [
                'key' => $key,
                'label' => $label,
                'total' => count($rows),
                'items' => array_map(fn ($row) => $this->item($row), array_slice($rows, 0, self::PREVIEW)),
            ];
        }

        usort($groups, fn ($a, $b) => [$b['total'], $a['label']] <=> [$a['total'], $b['label']]);

        return $groups;
    }

    private function groupFor(DocumentEvidence $row): ?string
    {
        $data = is_array($row->data) ? $row->data : [];
        if ($row->kind === 'metric') {
            $measurement = $this->measurements->parse($data['value'] ?? null, $data['unit'] ?? null, $data['label'] ?? null);

            return match ($measurement?->kind) {
                'currency' => 'financial',
                'percent', 'ratio', 'change' => 'shares',
                default => 'operational',
            };
        }
        if ($row->kind === 'entity') {
            return match ($data['entity_type'] ?? null) {
                'organization', 'contract' => 'organizations',
                'person' => 'people',
                'regulator', 'department' => 'governance',
                'location' => 'geography',
                'reference' => 'references',
                'date' => 'dates',
                default => null,
            };
        }

        return match ($row->kind) {
            'definition' => 'definitions',
            'fact' => 'context',
            default => null,
        };
    }

    /** IDs and the short descriptors the page renders - never a second copy of the source text. */
    private function item(DocumentEvidence $row): array
    {
        $data = is_array($row->data) ? $row->data : [];
        $pages = array_values(array_unique(array_filter(array_column($row->sources ?? [], 'page'), fn ($page) => $page !== null)));

        return [
            'sourceId' => $row->source_id ?: 'evidence:'.$row->id,
            'kind' => $row->kind,
            'label' => $this->clip($data['label'] ?? ''),
            'value' => $this->clip($data['value'] ?? '', 220),
            'unit' => $data['unit'] ?? null,
            'period' => $data['period'] ?? null,
            'subject' => $this->clip($data['subject'] ?? ''),
            'confidence' => round((float) ($data['confidence'] ?? 0), 2),
            'page' => count($pages) === 1 ? (int) reset($pages) : null,
        ];
    }

    private function clip(mixed $value, int $limit = 120): string
    {
        $text = trim((string) $value);

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1).'…' : $text;
    }
}
