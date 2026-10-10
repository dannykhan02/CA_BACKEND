<?php

namespace Tests\Unit;

use App\Services\Intelligence\Brief\BriefVerifier;
use App\Services\Intelligence\Materiality\DateRoleResolver;
use App\Services\Intelligence\Values\ValueParser;
use Tests\TestCase;

/**
 * The 29 claims the paid B2 evaluation returned, frozen as regression fixtures.
 *
 * `review-judgements.json` is the source of truth for the verdicts and none of them was changed to
 * make a test pass: the fixture carries the independent reviewer's label beside the evaluation's own
 * verifier verdict, and this test records what the shared grounding layer now does with each claim.
 *
 * What is pinned is the grounding layer - the thing this branch changes. The `unsupported_key_figure`
 * gate is not: it depends on the full supplied evidence set, which the blinded packet deliberately
 * does not preserve, and the key-figure rules are frozen. Where a claim was also rejected on that
 * gate the fixture records it, and the expectation below speaks only about grounding.
 *
 * Claims are verified the way B2 verifies them: as a `docintel_ai` / `stated` block, with each
 * record's own label and subject offered as `confirmed_entity_names`, which is exactly what
 * NarrativeVerifier::grounding() does.
 */
class IntelligenceB1GroundingReviewedClaimsTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function fixture(): array
    {
        return json_decode(file_get_contents(base_path(
            'tests/Fixtures/intelligence-v2/b1-grounding/reviewed-claims.json')),
            true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * The grounding outcome each reviewed claim must have. Keyed by item id, so a changed verdict
     * is a named failure rather than a moved count.
     *
     * `[]` means every grounding check passes. A listed reason means the claim is still refused, and
     * the comment says why that is the contract rather than a defect.
     *
     * @return array<string,list<string>>
     */
    public static function expectedGrounding(): array
    {
        return [
            // --- reviewed `supported`, now grounded --------------------------------------------
            'C03' => [], 'C04' => [], 'C05' => [], 'C06' => [], 'C07' => [], 'C08' => [],
            'C09' => [], 'C10' => [], 'C11' => [], 'C12' => [], 'C14' => [], 'C15' => [],
            'C16' => [], 'C19' => [], 'C22' => [], 'C25' => [], 'C26' => [], 'C28' => [],
            'C29' => [],

            // --- reviewed `supported`, still refused, and why ---------------------------------
            // A claim naming "Core Resources" against records whose subject is "UNICEF" and whose
            // labels are "Core Resources income", "Core Resources income from private sector" and
            // so on. The mention is a prefix of a record *label*, not a name the record owns, and
            // matching it would mean inferring a longer entity from a shorter one - which the
            // contract refuses on purpose, and which this branch does not change.
            'C01' => ['entities_grounded'],
            'C02' => ['entities_grounded'],
            'C17' => ['entities_grounded'],
            'C21' => ['entities_grounded'],
            'C24' => ['entities_grounded'],
            // "Swachh Bharat" and "Clean India" appear only inside the free-text `value` of a
            // non-entity record. The contract reads entity names from `subject`, an entity record's
            // `value`/`aliases`, `confirmed_entity_names` and a confirmed `entity_ref` - never from
            // arbitrary record prose. Grounding them would need substring search inside a record's
            // value, which is the fuzzy matching this layer exists to refuse.
            'C18' => ['entities_grounded'],
            'C23' => ['entities_grounded'],

            // --- reviewed `partially_supported`: the residual percentage -----------------------
            // The reviewer found 46% and 49% in the cited chart quote and 5% nowhere in it, reading
            // as 100-46-49. These must stay refused. `numbers_grounded` is the load-bearing reason:
            // the 5% record no longer types at all, because "5%" occurs in its quote only inside
            // "45%" - a neighbouring bar, not this record's figure. The entity reason is incidental.
            // `units_consistent` follows from it: the claim's "5%" unit token has no grounded
            // number left to attach to.
            'C13' => ['numbers_grounded', 'units_consistent', 'entities_grounded'],
            'C20' => ['numbers_grounded', 'units_consistent', 'entities_grounded'],
            'C27' => ['numbers_grounded', 'units_consistent', 'entities_grounded'],
        ];
    }

    /**
     * Stage A's own typing, re-derived from this record's stored `data` and `sources` exactly as
     * MaterialityReadModel does it: ValueParser, then the date-role resolver, with no other input.
     *
     * @param  array<string,mixed>  $record
     * @return array<string,mixed>
     */
    private function retype(array $record): array
    {
        $data = $record['data'] ?? [];
        $quotes = array_values(array_filter(
            array_column($record['sources'] ?? [], 'quote'), 'is_string'));
        if ($quotes === [] && is_string($data['quote'] ?? null)) {
            $quotes[] = $data['quote'];
        }
        $typed = app(ValueParser::class)->parse($data, $quotes);

        return app(DateRoleResolver::class)->resolve($typed,
            $data + ['kind' => $record['kind'] ?? null], $quotes);
    }

    /** @return array<string,array{0:string}> */
    public static function reviewedItems(): array
    {
        $cases = [];
        foreach (array_keys(self::expectedGrounding()) as $item) {
            $cases[$item] = [$item];
        }
        ksort($cases);

        return $cases;
    }

    public function test_the_fixture_holds_all_twenty_nine_reviewed_claims(): void
    {
        $items = self::fixture()['items'];
        self::assertCount(29, $items);
        self::assertCount(29, self::expectedGrounding());
        self::assertSame(array_map(static fn (array $i) => $i['item'], $items),
            array_keys(self::reviewedItems()));

        // The frozen verdicts, exactly as the evaluation recorded them.
        $review = array_count_values(array_map(
            static fn (array $i) => $i['independent_review']['verdict'], $items));
        self::assertSame(['supported' => 26, 'partially_supported' => 3], $review);
        $verifier = array_count_values(array_map(
            static fn (array $i) => $i['evaluation']['verifier_verdict'], $items));
        self::assertSame(['rejected' => 22, 'accepted' => 7], $verifier);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reviewedItems')]
    public function test_reviewed_claim_grounds_as_recorded(string $item): void
    {
        $fixture = null;
        foreach (self::fixture()['items'] as $candidate) {
            if ($candidate['item'] === $item) {
                $fixture = $candidate;
            }
        }
        self::assertNotNull($fixture, $item);

        $records = [];
        foreach ($fixture['records'] as $id => $record) {
            // The fixture froze each record as the evaluation supplied it, `typed` included - and
            // under the old code that `typed.value` was sometimes null, which is defect 3 itself.
            // Stage A does not treat stored typing as canonical: MaterialityReadModel re-derives it
            // from the record's own `data` and `sources` on every read. Re-derive it here the same
            // way, or the test would pin the broken projection instead of the fixed one.
            $record['typed'] = $this->retype($record);

            // NarrativeVerifier offers each record's own label and subject as names it may be
            // called by. Record-local: no record contributes a name to any other.
            $record['confirmed_entity_names'] = array_values(array_filter([
                $record['data']['label'] ?? null, $record['data']['subject'] ?? null], 'is_string'));
            $records[$id] = $record;
        }

        $block = ['type' => 'finding', 'text' => $fixture['claim'], 'detail' => null,
            'origin' => 'docintel_ai', 'assertion' => 'stated', 'cites' => $fixture['cites'],
            'attribution' => ['speaker' => null, 'role' => 'unattributed', 'reported' => false,
                'evidence_ref' => null], 'template_id' => null, 'absence_check' => null];

        $result = app(BriefVerifier::class)->verify($block, $records, array_keys($records));

        $expected = self::expectedGrounding()[$item];
        sort($expected);
        $actual = $result['failed_reasons'];
        sort($actual);
        self::assertSame($expected, $actual, $item.': '.$fixture['claim']);

        // A claim the reviewer called short of fully supported must never come out clean.
        if ($fixture['independent_review']['verdict'] === 'partially_supported') {
            self::assertNotSame('passed', $result['status'], $item.' must stay refused');
        }
    }

    /**
     * The claims the evaluation accepted must still be accepted. Nothing here may be bought by
     * loosening something that already worked.
     */
    public function test_no_previously_accepted_claim_regressed(): void
    {
        $expected = self::expectedGrounding();
        foreach (self::fixture()['items'] as $item) {
            if ($item['evaluation']['verifier_verdict'] === 'accepted') {
                self::assertSame([], $expected[$item['item']],
                    $item['item'].' was accepted by the evaluation and must stay grounded');
            }
        }
    }

    /**
     * The residual-percentage known-negative is one of the reviewed 29 (C13, C20, C27), so it is
     * not duplicated as a separate adversarial fixture. Its rejection must rest on the number, not
     * only on the entity check, or the guard would be one entity-matching change away from
     * admitting an arithmetic-derived figure.
     */
    public function test_the_residual_percentage_is_refused_on_its_number(): void
    {
        foreach (['C13', 'C20', 'C27'] as $item) {
            self::assertContains('numbers_grounded', self::expectedGrounding()[$item], $item);
            $fixture = null;
            foreach (self::fixture()['items'] as $candidate) {
                if ($candidate['item'] === $item) {
                    $fixture = $candidate;
                }
            }
            self::assertSame('partially_supported', $fixture['independent_review']['verdict'], $item);
            self::assertSame(['figure'], $fixture['independent_review']['components_failing'], $item);
        }
    }
}
