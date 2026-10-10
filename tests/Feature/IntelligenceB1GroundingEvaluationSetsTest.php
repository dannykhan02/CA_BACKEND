<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Services\Intelligence\B2\BriefReadService;
use App\Services\Intelligence\B2\StageASnapshot;
use App\Services\Intelligence\Brief\BriefAssembler;
use App\Services\Intelligence\Brief\BriefVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsIntelligenceFixtures;
use Tests\TestCase;

/**
 * B1's own output on the two frozen evidence sets from the paid B2 evaluation, assembled from the
 * canonical records exactly as extraction stored them — quotes included, line wrapping and all,
 * because the wrapping is what defect 3 turned out to be.
 *
 * The point of the test is the figure-bearing blocks. The evaluation found that B1 silently dropped
 * every block carrying a monetary figure on both documents and served a headline plus a coverage
 * note, so what is asserted here is that the money is back and that it is the record's own money:
 * same amount, same scale, same currency, same source quote.
 *
 * Set `B1_DUMP_DIR` to write each set's served blocks to `<dir>/b1-<set>.json`. The dump happens
 * before any assertion, so the same test captures the BEFORE picture on an unfixed tree.
 */
class IntelligenceB1GroundingEvaluationSetsTest extends TestCase
{
    use BuildsIntelligenceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['intelligence_v2.enabled' => true, 'intelligence_v2.brief.enabled' => true,
            'intelligence_v2.b2.enabled' => false]);
    }

    /** @return array<string,array{0:string}> */
    public static function evidenceSets(): array
    {
        return ['india_wash' => ['india_wash'], 'unicef_reduced' => ['unicef_reduced']];
    }

    /** @return array<string,mixed> */
    private static function fixture(): array
    {
        return json_decode(file_get_contents(base_path(
            'tests/Fixtures/intelligence-v2/b1-grounding/evaluation-evidence-sets.json')),
            true, 512, JSON_THROW_ON_ERROR);
    }

    /** Seeds one evidence set as stored evidence, in the shape the incremental pipeline persists. */
    private function seedSet(string $set): Document
    {
        $document = $this->intelligenceDocument($set.'.pdf');
        $records = self::fixture()['sets'][$set]['records'];
        $ordinal = 0;
        foreach ($records as $record) {
            $ordinal++;
            $shared = ['subject' => (string) ($record['subject'] ?? ''), 'quote' => $record['quote'],
                'quantity_kind' => $record['quantity_kind'], 'metric_type' => $record['metric_type'],
                'value_basis' => $record['value_basis'], 'severity' => $record['severity']];
            if ($record['kind'] === 'metric') {
                $this->metricFinding($document, $record['label'], $record['value'],
                    $record['unit'], $record['period'], $shared);

                continue;
            }
            $this->evidenceRow($document, $record['kind'], $shared + ['label' => $record['label'],
                'value' => $record['value'], 'unit' => $record['unit'], 'period' => $record['period']],
                $record['kind'].':'.$ordinal, $record['page']);
        }

        return $document->fresh();
    }

    /** The blocks a reader is actually served, through the API read path. */
    private function served(Document $document): array
    {
        return app(BriefReadService::class)->forDocument($document, StageASnapshot::today())['blocks'];
    }

    /** Blocks that carry a figure: anything typed with a number, which is what the drop removed. */
    private function figureBearing(array $blocks): array
    {
        return array_values(array_filter($blocks, static fn (array $block): bool => is_numeric(
            $block['typed']['value']['number'] ?? null)));
    }

    #[DataProvider('evidenceSets')]
    public function test_b1_serves_the_money_its_own_records_state(string $set): void
    {
        $document = $this->seedSet($set);
        $blocks = $this->served($document);

        $dump = getenv('B1_DUMP_DIR');
        if (is_string($dump) && $dump !== '') {
            @mkdir($dump, 0777, true);
            file_put_contents($dump.'/b1-'.$set.'.json', json_encode([
                'set' => $set,
                'block_count' => count($blocks),
                'types' => array_column($blocks, 'type'),
                'figure_bearing' => array_map(static fn (array $b) => [
                    'type' => $b['type'], 'text' => $b['text'], 'cites' => $b['cites'],
                    'typed_value' => $b['typed']['value'] ?? null,
                ], $this->figureBearing($blocks)),
                'blocks' => $blocks,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        // The served Brief is still B1's own output, byte for byte.
        $asOf = StageASnapshot::today();
        $snapshot = app(StageASnapshot::class)->build($document, $asOf);
        $direct = app(BriefAssembler::class)->assemble($snapshot['document_name'],
            $snapshot['document_type'], $snapshot['records'], $snapshot['assignments'],
            $snapshot['coverage'], $asOf, $snapshot['forced_overflow']);
        self::assertSame(json_encode($direct['blocks']), json_encode($blocks), $set);

        // What the evaluation measured as broken: a reader got a headline and a coverage note.
        $figures = $this->figureBearing($blocks);
        self::assertNotEmpty($figures, $set.': B1 served no figure-bearing block at all');
        self::assertNotSame(['headline', 'coverage_note'],
            array_values(array_unique(array_column($blocks, 'type'))), $set);

        // Every restored figure is its own record's figure: the amount, scale and currency come
        // from the cited record, and that record's quote states the value.
        $bySource = [];
        foreach ($snapshot['records'] as $record) {
            $bySource[$record['source_id']] = $record;
        }
        foreach ($figures as $block) {
            $cited = $bySource[$block['cites'][0]] ?? null;
            self::assertNotNull($cited, $set.': '.$block['text']);
            $value = $cited['typed']['value'];
            self::assertSame($value, $block['typed']['value'], $set.': '.$block['text']);
            self::assertStringContainsString((string) $value['raw'], $block['text'], $set);
            $quote = preg_replace('/\s+/u', ' ', (string) ($cited['sources'][0]['quote'] ?? ''));
            self::assertStringContainsString(preg_replace('/\s+/u', ' ', (string) $value['raw']),
                (string) $quote, $set.': the record quote does not state its own value');
        }
    }

    /**
     * The monetary figures the evaluation recorded as dropped, named one by one.
     *
     * What is asserted is the grounding contract, not a seat in the Brief: each figure must now be
     * typed from its own record and be groundable. Whether it then appears depends on
     * `key_figures.max`, which is a frozen selection rule - on `unicef_reduced` ten monetary
     * records now compete for six slots, and the three smallest lose to the five billions and the
     * Core Resources total. That is selection working, not grounding failing, so the two are
     * asserted separately.
     */
    #[DataProvider('droppedMoney')]
    public function test_each_monetary_figure_the_evaluation_lost_grounds_again(
        string $set, string $label, string $amount, bool $served): void
    {
        $document = $this->seedSet($set);
        $snapshot = app(StageASnapshot::class)->build($document, StageASnapshot::today());
        $verifier = app(BriefVerifier::class);

        $found = null;
        foreach ($snapshot['records'] as $record) {
            if (($record['data']['label'] ?? null) === $label
                && ($record['data']['value'] ?? null) === $amount) {
                $found = $record;
            }
        }
        self::assertNotNull($found, $set.' / '.$label.': record not seeded');

        $value = $found['typed']['value'];
        self::assertIsArray($value, $set.' / '.$label.': still types to null');
        self::assertSame('money', $value['type']);
        self::assertSame('USD', $verifier->recordCurrencyCode($value),
            $set.' / '.$label.': the ISO code is not resolved from the record');
        self::assertTrue($verifier->groundableValue($value),
            $set.' / '.$label.': typed but still cannot ground a claim');

        $texts = implode("\n", array_column($this->served($document), 'text'));
        $served
            ? self::assertStringContainsString($amount, $texts, $set.' / '.$label)
            : self::assertStringNotContainsString($label.': '.$amount, $texts,
                $set.' / '.$label.': expected to lose its key-figure slot, but it is served');
    }

    /** @return array<string,array{0:string,1:string,2:string,3:bool}> */
    public static function droppedMoney(): array
    {
        return [
            'India WASH investment' => ['india_wash',
                'Government of India investment in water and sanitation', 'over $120 billion', true],
            'Core Resources total' => ['unicef_reduced',
                'Core Resources income', '$1.584 billion', true],
            // Groundable, but outside the six key-figure slots on this evidence set.
            'Core Resources private sector' => ['unicef_reduced',
                'Core Resources income from private sector', '$724.9 million', false],
            'Core Resources public sector' => ['unicef_reduced',
                'Core Resources income from public sector', '$512.6 million', false],
            'Core Resources other partners' => ['unicef_reduced',
                'Core Resources income from other partners', '$346.1 million', false],
        ];
    }

    /** Every monetary record the set owns now types; the cap is the only thing that limits B1. */
    #[DataProvider('evidenceSets')]
    public function test_every_monetary_record_types_and_the_cap_is_the_only_limit(string $set): void
    {
        $document = $this->seedSet($set);
        $snapshot = app(StageASnapshot::class)->build($document, StageASnapshot::today());
        $verifier = app(BriefVerifier::class);

        $money = array_values(array_filter($snapshot['records'], static fn (array $r): bool
            => ($r['data']['quantity_kind'] ?? null) === 'monetary'));
        self::assertNotEmpty($money, $set);
        foreach ($money as $record) {
            self::assertTrue($verifier->groundableValue($record['typed']['value'] ?? null),
                $set.': '.($record['data']['label'] ?? '').' cannot ground');
        }

        $measures = array_filter($this->served($document),
            static fn (array $b): bool => $b['type'] === 'measure');
        self::assertCount(min(count($money), (int) config('intelligence_v2.brief.key_figures.max')),
            $measures, $set);
    }
}
