<?php

namespace App\Services\Intelligence\Brief;

use App\Services\AI\Incremental\EvidenceMerger;
use App\Services\Intelligence\Materiality\MaterialityScorer;
use App\Services\Intelligence\Materiality\SignalEvaluator;

/** Selects cited currency measures without changing their materiality assignment. */
class KeyFigureSelector
{
    public function __construct(private SignalEvaluator $signals, private EvidenceMerger $normalizer) {}

    /** @param list<array<string,mixed>> $records @param array<string,array<string,mixed>> $assignments
     *  @return list<array<string,mixed>>
     */
    public function select(array $records, array $assignments = [], ?\DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new \DateTimeImmutable;
        $eligible = array_values(array_filter($records, static function (array $record): bool {
            $value = $record['typed']['value'] ?? null;

            return ($record['kind'] ?? null) === 'metric'
                && ($record['provenance']['origin'] ?? null) === 'document'
                && ($value['type'] ?? null) === 'money'
                && ($value['unit_kind'] ?? null) === 'currency'
                && is_string($value['currency'] ?? null) && $value['currency'] !== ''
                && is_numeric($value['number'] ?? null) && is_finite((float) $value['number'])
                && is_string($record['source_id'] ?? null);
        }));
        usort($eligible, fn ($a, $b) => MaterialityScorer::compareTiebreak($a, $b,
            $assignments[$a['identity']] ?? [], $assignments[$b['identity']] ?? []));
        $deduped = [];
        $seen = [];
        foreach ($eligible as $record) {
            $value = $record['typed']['value'];
            $period = $record['typed']['dates']['period_covered']['period']['text']
                ?? $record['data']['period'] ?? '';
            $key = implode('|', [$value['currency'], sprintf('%.17g', (float) $value['number']),
                $this->normalizer->normalize((string) $period)]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $record;
        }
        $patterns = config('intelligence_v2.brief.key_figures.total_label_patterns');
        $rank = [];
        foreach ($deduped as $record) {
            $label = (string) ($record['data']['label'] ?? '');
            $total = false;
            foreach ($patterns as $pattern) {
                if (preg_match('/(?<!\p{L})'.preg_quote($pattern, '/').'(?!\p{L})/iu', $label)) {
                    $total = true;
                    break;
                }
            }
            $magnitude = $this->signals->values($record, $records, [], $asOf)['monetary_magnitude']['value'];
            $rank[$record['identity']] = ['total' => $total, 'magnitude' => $magnitude];
        }
        usort($deduped, function ($a, $b) use ($rank, $assignments) {
            $left = $rank[$a['identity']];
            $right = $rank[$b['identity']];

            return ($right['total'] <=> $left['total'])
                ?: ($right['magnitude'] <=> $left['magnitude'])
                ?: MaterialityScorer::compareTiebreak($a, $b,
                    $assignments[$a['identity']] ?? [], $assignments[$b['identity']] ?? []);
        });

        return array_slice($deduped, 0, config('intelligence_v2.brief.key_figures.max'));
    }
}
