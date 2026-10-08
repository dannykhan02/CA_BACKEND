<?php

namespace App\Services\Intelligence\Brief;

use App\Models\Document;
use App\Services\Intelligence\Attention\AttentionStateBuilder;
use App\Services\Intelligence\Attention\HistoricalRiskRule;
use App\Services\Intelligence\Brief\Templates\DeterministicTemplates;
use App\Services\Intelligence\CoverageStateBuilder;
use App\Services\Intelligence\Materiality\MaterialityScorer;

/** Assembles a read-only, deterministic Brief from the Stage A read model. */
class BriefAssembler
{
    public function __construct(private KeyFigureSelector $figures, private DeterministicTemplates $templates,
        private HistoricalRiskRule $historical, private BriefVerifier $verifier,
        private LegacyBriefAdapter $legacy, private MaterialityScorer $scorer,
        private CoverageStateBuilder $coverageStates) {}

    /** Normal-route entry point; all adapter reads are scoped to document and workspace. */
    public function assembleLegacy(Document $document, \DateTimeImmutable $asOf): array
    {
        $records = $this->legacy->records($document);
        $assignments = $this->scorer->assign($records, [
            'span_count' => null, 'cited_source_ids' => [], 'comparable_source_ids' => [],
        ], $asOf);
        $overflow = count(array_filter($assignments, fn ($item) => $item['overflow_from_forced']));
        $truncated = $overflow > 0 || count(array_filter($assignments,
            fn ($item) => $item['band_qualified'] && $item['tier'] !== 1)) > 0;
        $coverage = $this->coverageStates->build(['route' => 'normal', 'synthesis' => 'completed'], true, $truncated);

        return $this->assemble((string) $document->name, (string) $document->type,
            $records, $assignments, $coverage, $asOf, $overflow);
    }

    /** @param list<array<string,mixed>> $records @param array<string,array<string,mixed>> $assignments
     *  @param array<string,mixed> $coverage @return array<string,mixed>
     */
    public function assemble(string $documentName, string $documentType, array $records, array $assignments,
        array $coverage, \DateTimeImmutable $asOf, int $forcedOverflow = 0): array
    {
        $blocks = [];
        $attentionBuilder = new AttentionStateBuilder($this->historical, config('intelligence_v2.attention'));
        $blocks[] = $this->block('headline', 'headline.document_identity', null,
            ['document_name' => $documentName, 'document_type' => $documentType], 1);
        $byIdentity = [];
        foreach ($records as $record) {
            $byIdentity[$record['identity']] = $record;
        }
        $tierOne = [];
        foreach ($assignments as $identity => $assignment) {
            if (($assignment['tier'] ?? null) === 1 && isset($byIdentity[$identity])) {
                $tierOne[] = [$byIdentity[$identity], $assignment];
            }
        }
        usort($tierOne, static fn ($a, $b) => ($a[1]['forced_priority'] ?? PHP_INT_MAX)
            <=> ($b[1]['forced_priority'] ?? PHP_INT_MAX)
            ?: ($b[1]['score'] ?? 0) <=> ($a[1]['score'] ?? 0)
            ?: MaterialityScorer::compareTiebreak($a[0], $b[0], $a[1], $b[1]));
        foreach ($tierOne as [$record, $assignment]) {
            if (($record['provenance']['origin'] ?? null) !== 'document') {
                continue;
            }
            $state = $attentionBuilder->build($record, $assignment, $records, $asOf);
            if (! in_array($state['state'], ['needs_attention', 'watch'], true)) {
                continue;
            }
            $date = $record['typed']['dates']['due_date'] ?? [];
            $id = match ($assignment['forced_rule'] ?? null) {
                'overdue_dated_obligation' => 'attention.overdue',
                'imminent_dated_obligation' => 'attention.imminent',
                'critical_risk' => 'attention.critical_risk',
                default => null,
            };
            if ($id === null) {
                continue;
            }
            $blocks[] = $this->block('attention', $id, $record,
                ['label' => $record['data']['label'] ?? '', 'date' => $date], 1, $state);
        }
        $timelines = [];
        foreach ($records as $record) {
            if (($record['provenance']['origin'] ?? null) !== 'document'
                || ! in_array($record['kind'] ?? null, ['obligation', 'deadline'], true)
                || ! in_array($record['status'] ?? null, [null, 'open'], true)) {
                continue;
            }
            $assignment = $assignments[$record['identity']] ?? ['tier' => 3];
            $state = $attentionBuilder->build($record, $assignment, $records, $asOf);
            if (in_array($state['state'], ['resolved'], true) || in_array('historical_context', $state['reasons'], true)) {
                continue;
            }
            $date = $record['typed']['dates']['due_date'] ?? [];
            $id = match ($date['resolution'] ?? null) {
                'calendar' => 'timeline.calendar_due', 'period' => 'timeline.period_due',
                'relative' => 'timeline.relative_due', default => null,
            };
            if ($id !== null) {
                $timelines[] = [$record, $assignment, $state, $id, $date];
            }
        }
        usort($timelines, static fn ($a, $b) => ($a[1]['tier'] ?? 4) <=> ($b[1]['tier'] ?? 4)
            ?: MaterialityScorer::compareTiebreak($a[0], $b[0], $a[1], $b[1]));
        foreach ($timelines as [$record, $assignment, $state, $id, $date]) {
            $blocks[] = $this->block('timeline', $id, $record,
                ['label' => $record['data']['label'] ?? '', 'date' => $date], $assignment['tier'], $state);
        }
        foreach ($this->figures->select($records, $asOf, $assignments) as $record) {
            $value = $record['typed']['value'];
            $period = $record['typed']['dates']['period_covered']['period']['text'] ?? null;
            $blocks[] = $this->block('measure', 'measure.period_value', $record,
                ['label' => $record['data']['label'] ?? '', 'value' => $value, 'period' => $period,
                    'period_typed' => $record['typed']['dates']['period_covered'] ?? null],
                $assignments[$record['identity']]['tier'] ?? 3);
        }
        if (($coverage['state'] ?? null) !== 'complete' || ($coverage['tier1_truncated'] ?? false) || $forcedOverflow > 0) {
            $state = $coverage['state'] ?? 'unavailable';
            $id = in_array('legacy_route', $coverage['reasons'] ?? [], true)
                ? 'coverage_note.legacy_route' : 'coverage_note.'.($state === 'complete' ? 'bounded' : $state);
            $reasons = $coverage['reasons'] ?? [];
            if (($coverage['tier1_truncated'] ?? false) && ! in_array('tier1_truncated', $reasons, true)) {
                $reasons[] = 'tier1_truncated';
            }
            if ($forcedOverflow > 0 && ! in_array('forced_overflow', $reasons, true)) {
                $reasons[] = 'forced_overflow';
            }
            $blocks[] = $this->block('coverage_note', $id, null, ['reasons' => $reasons], 3);
        }
        $bySource = [];
        foreach ($records as $record) {
            $bySource[$record['source_id']] = $record;
        }
        $blocks = array_values(array_filter($blocks, function (array $block) use ($bySource): bool {
            if (in_array($block['type'], ['headline', 'coverage_note'], true)) {
                return true;
            }
            $checks = $this->verifier->verify($block, $bySource, array_keys($bySource))['checks'];
            foreach ($checks as $check) {
                if (in_array($check['check'], ['cites_available', 'numbers_grounded', 'dates_grounded',
                    'periods_grounded', 'units_consistent'], true) && $check['status'] === 'failed') {
                    return false;
                }
            }

            return true;
        }));
        foreach ($blocks as $index => &$block) {
            $block['order'] = $index;
        }
        unset($block);

        return ['blocks' => $blocks, 'ai_blocks_available' => false,
            'ai_blocks_rejected' => 0, 'template_version' => config('intelligence_v2.brief.template_version')];
    }

    /** @param array<string,mixed>|null $record @param array<string,mixed> $input @return array<string,mixed> */
    private function block(string $type, string $templateId, ?array $record, array $input, int $tier,
        ?array $attention = null): array
    {
        $sourceId = $record['source_id'] ?? null;
        $cites = is_string($sourceId) ? [$sourceId] : [];
        $typed = [];
        if (isset($input['value'])) {
            $typed['value'] = $input['value'];
        }
        if (isset($input['date']) && $input['date'] !== []) {
            $typed['due_date'] = $input['date'];
        }
        if (is_array($input['period_typed'] ?? null)) {
            $typed['period_covered'] = $input['period_typed'];
        }
        $input['attribution'] = $record['provenance']['attribution'] ?? null;

        return ['id' => $templateId.':'.($record['identity'] ?? 'document'), 'type' => $type, 'order' => 0,
            'text' => $this->templates->render($templateId, $input), 'detail' => null,
            'origin' => 'docintel_deterministic', 'assertion' => 'derived',
            'attribution' => $record['provenance']['attribution'] ?? [
                'speaker' => null, 'role' => 'unattributed', 'reported' => false, 'evidence_ref' => null],
            'cites' => $cites, 'evidence' => $record === null ? [] : $this->evidence($record), 'typed' => $typed,
            'template_id' => $templateId, 'template_version' => config('intelligence_v2.brief.template_version'),
            'verification' => null, 'absence_check' => null, 'tier' => $tier,
            'attention' => $attention, 'chart_id' => null, 'ai_generated' => false];
    }

    /** @param array<string,mixed> $record @return list<array<string,mixed>> */
    private function evidence(array $record): array
    {
        $refs = [];
        $pages = array_unique(array_map(static fn ($source) => $source['page'] ?? null, $record['sources'] ?? []));
        $singlePage = count($pages) === 1 && is_int(reset($pages)) ? reset($pages) : null;
        foreach ($record['sources'] ?? [] as $source) {
            $recordId = $record['record_id'] ?? null;
            if (! is_string($recordId) || ! is_int($source['start_offset'] ?? null)
                || ! is_int($source['end_offset'] ?? null)) {
                continue;
            }
            $page = $singlePage;
            $refs[] = ['record_id' => $recordId, 'source_id' => $record['source_id'],
                'span_id' => $source['span_id'] ?? null, 'extraction_version' => $record['extraction_version'] ?? null,
                'chunk_id' => (string) ($source['chunk_id'] ?? ''), 'start_offset' => $source['start_offset'],
                'end_offset' => $source['end_offset'], 'quote' => (string) ($source['quote'] ?? ''),
                'page' => $page, 'highlight' => ['mode' => $page === null ? 'none' : 'page_only',
                    'start_offset' => null, 'end_offset' => null, 'needle' => null,
                    'occurrence' => null, 'occurrences' => null, 'page' => $page]];
        }

        return $refs;
    }
}
