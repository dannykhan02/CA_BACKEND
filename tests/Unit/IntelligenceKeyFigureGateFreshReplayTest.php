<?php

namespace Tests\Unit;

use App\Services\Intelligence\B2\NarrativeVerifier;
use Tests\TestCase;

/** Frozen six-call claims; no provider construction or request. */
class IntelligenceKeyFigureGateFreshReplayTest extends TestCase
{
    private function evidence(string $file): array
    {
        return json_decode(file_get_contents(base_path(
            'docs/tasks/b2-entity-ownership-paid-retest-evidence/'.$file)),
            true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_all_fresh_claims_and_whole_narratives(): void
    {
        $review = array_column($this->evidence('unblinded.json'), null, 'item_id');
        $seed = $this->evidence('seed.json');
        $calls = [];
        foreach ($this->evidence('review-packet.json') as $item) {
            $judgment = $review[$item['item_id']];
            $call = $judgment['call_index'];
            $cites = $judgment['cites'];
            self::assertCount(count($cites), $item['cited_evidence'], $item['item_id']);
            foreach ($cites as $index => $id) {
                $record = $item['cited_evidence'][$index]['record'];
                $record['source_id'] = $id;
                $calls[$call]['records'][$id] = $record;
            }
            $calls[$call]['items'][$judgment['claim_index']] = [
                'id' => $item['item_id'], 'claim' => $item['claim'], 'cites' => $cites,
                'judgment' => $judgment,
            ];
        }
        ksort($calls);
        self::assertCount(6, $calls);

        $accepted = [];
        $rejected = [];
        $statuses = [];
        foreach ($calls as $call => $data) {
            ksort($data['items']);
            $items = array_values($data['items']);
            $narrative = array_map(static fn (array $item) => [
                'claim' => $item['claim'], 'cites' => $item['cites'],
            ], $items);
            $records = $data['records'];
            $verdict = app(NarrativeVerifier::class)->verify(['narrative' => $narrative],
                array_keys($records), $records, $seed[$call]['key_figure_ids']);
            $statuses[] = $verdict['status'];
            $reasonsByIndex = array_column($verdict['rejected'], 'reasons', 'index');
            foreach ($items as $index => $item) {
                $id = $item['id'];
                $reasons = $reasonsByIndex[$index] ?? [];
                if ($reasons === []) {
                    $accepted[] = $id;
                    self::assertSame('supported', $item['judgment']['review_label'], $id);
                } else {
                    $rejected[$id] = $reasons;
                    self::assertNotSame('accepted', $item['judgment']['verifier_verdict'], $id);
                }
            }
        }
        foreach (['F28', 'F35', 'F44'] as $id) {
            self::assertContains($id, $accepted);
        }
        foreach (['F03', 'F19'] as $id) {
            self::assertSame(['brief_entities_grounded'], $rejected[$id] ?? [], $id);
        }
        self::assertCount(28, $accepted);
        self::assertCount(8, $rejected);
        self::assertSame(['rejected', 'verified', 'rejected', 'rejected', 'rejected', 'rejected'], $statuses);
    }
}
