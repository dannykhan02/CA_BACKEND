<?php

namespace Tests\Unit;

use App\Services\Intelligence\B2\NarrativeVerifier;
use App\Services\Intelligence\Materiality\DateRoleResolver;
use App\Services\Intelligence\Values\ValueParser;
use Tests\TestCase;

/** Frozen canonical records and provider responses; this test never constructs a provider. */
class IntelligenceEntityOwnershipReplayTest extends TestCase
{
    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/intelligence-v2/'.$name)),
            true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_fresh_stored_claims_and_six_whole_narratives(): void
    {
        $recovered = [];
        $still = [];
        $regressions = [];
        $unsafe = [];
        $verified = [];
        foreach ($this->fixture('entity-ownership-replay.json')['documents'] as $document) {
            $records = $document['records'];
            $verdict = app(NarrativeVerifier::class)->verify(['narrative' => $document['narrative']],
                array_keys($records), $records, $document['key_figures']);
            $verified[] = $verdict['status'];
            $rejections = array_column($verdict['rejected'], 'reasons', 'index');
            foreach ($document['items'] as $index => $item) {
                $reasons = $rejections[$index] ?? [];
                if ($item['verifier_verdict'] === 'accepted' && $reasons !== []) {
                    $regressions[$item['item_id']] = $reasons;
                }
                if ($item['independent_judgement'] !== 'supported' && $reasons === []) {
                    $unsafe[] = $item['item_id'];
                }
                if ($item['independent_judgement'] === 'supported'
                    && $item['verifier_verdict'] === 'rejected') {
                    if ($reasons === []) {
                        $recovered[] = $item['item_id'];
                    } else {
                        $still[$item['item_id']] = $reasons;
                    }
                }
            }
        }
        sort($recovered);
        self::assertSame([], $regressions, json_encode($regressions));
        self::assertSame([], $unsafe, 'OWNERSHIP_SAFETY_REGRESSION: '.implode(',', $unsafe));
        self::assertSame(['F02', 'F05', 'F10', 'F18', 'F22'], $recovered);
        self::assertSame(['F19', 'F28', 'F37', 'F44'], array_keys($still));
        self::assertSame(['brief_entities_grounded'], $still['F19']);
        foreach (['F28', 'F37', 'F44'] as $id) {
            self::assertSame(['unsupported_key_figure'], $still[$id], $id);
        }
        self::assertSame(2, count(array_filter($verified, fn ($status) => $status === 'verified')));
    }

    public function test_seven_paired_replay_claims_keep_key_figure_and_partial_gates(): void
    {
        $items = array_column($this->fixture('b1-grounding/reviewed-claims.json')['items'], null, 'item');
        $recovered = [];
        $still = [];
        $priorMin = config('intelligence_v2.b2.min_claims');
        config()->set('intelligence_v2.b2.min_claims', 1);
        try {
            foreach (['C01', 'C02', 'C17', 'C18', 'C21', 'C23', 'C24'] as $id) {
                $item = $items[$id];
                $records = [];
                foreach ($item['records'] as $sourceId => $record) {
                    $data = $record['data'];
                    $quotes = array_values(array_filter(array_column($record['sources'] ?? [], 'quote'), 'is_string'));
                    $record['typed'] = app(DateRoleResolver::class)->resolve(
                        app(ValueParser::class)->parse($data, $quotes),
                        $data + ['kind' => $record['kind']], $quotes);
                    $record['source_id'] = $sourceId;
                    $records[$sourceId] = $record;
                }
                $keyFigures = in_array($id, ['C21', 'C24'], true) ? [] : $item['cites'];
                $result = app(NarrativeVerifier::class)->verify(['narrative' => [[
                    'claim' => $item['claim'], 'cites' => $item['cites'],
                ]]], array_keys($records), $records, $keyFigures);
                if ($result['status'] === 'verified') {
                    $recovered[] = $id;
                } else {
                    $still[$id] = $result['rejected'][0]['reasons'] ?? $result['reasons'];
                }
            }
        } finally {
            config()->set('intelligence_v2.b2.min_claims', $priorMin);
        }
        self::assertSame(['C01', 'C02', 'C17', 'C18', 'C23'], $recovered, json_encode($still));
        self::assertSame(['C21', 'C24'], array_keys($still));
        self::assertSame(['unsupported_key_figure'], $still['C21']);
        self::assertSame(['unsupported_key_figure'], $still['C24']);
    }
}
