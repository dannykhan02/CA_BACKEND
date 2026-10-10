<?php

namespace Tests\Unit;

use App\Services\Intelligence\B2\NarrativeVerifier;
use App\Services\Intelligence\B2\NumericClaimGrounder;
use App\Services\Intelligence\Materiality\DateRoleResolver;
use App\Services\Intelligence\Values\ValueParser;
use Tests\TestCase;

class IntelligenceNumericClaimGroundingTest extends TestCase
{
    private function record(string $id, string $label, string $value, ?string $unit,
        string $subject = 'UNICEF', string $period = '2024'): array
    {
        $quote = "$label: $value in $period.";
        $data = ['kind' => 'metric', 'label' => $label, 'value' => $value, 'unit' => $unit,
            'subject' => $subject, 'period' => $period, 'quote' => $quote];
        $typed = app(DateRoleResolver::class)->resolve(
            app(ValueParser::class)->parse($data, [$quote]), $data, [$quote]);

        return ['source_id' => $id, 'kind' => 'metric', 'data' => $data, 'typed' => $typed,
            'provenance' => ['origin' => 'document', 'assertion' => 'stated',
                'attribution' => ['speaker' => null, 'role' => 'unattributed',
                    'reported' => false, 'evidence_ref' => null]],
            'sources' => [['quote' => $quote, 'page' => 1, 'start_offset' => 0,
                'end_offset' => strlen($quote)]]];
    }

    private function grounded(string $claim, array $records): bool
    {
        return app(NumericClaimGrounder::class)->grounded($claim, $records);
    }

    public function test_each_number_binds_to_its_own_typed_record(): void
    {
        $revenue = $this->record('m:revenue', 'Revenue', 'USD 12.4 billion', 'USD');
        $people = $this->record('m:people', 'Beneficiaries reached', '8 million', 'people');
        self::assertTrue($this->grounded('Revenue was USD 12.4 billion and 8 million people were reached.',
            [$revenue, $people]));
        self::assertFalse($this->grounded('Revenue was USD 8 million and 12.4 billion people were reached.',
            [$revenue, $people]));
        self::assertFalse($this->grounded('Revenue reached 8 million people.', [$revenue]));

        $expense = $this->record('m:expense', 'Expenses', 'USD 8 billion', 'USD');
        self::assertTrue($this->grounded('Revenue was USD 12.4 billion and expenses were USD 8 billion.',
            [$revenue, $expense]));
        self::assertFalse($this->grounded('Revenue was USD 8 billion and expenses were USD 12.4 billion.',
            [$revenue, $expense]));
    }

    public function test_unit_omission_needs_a_local_metric_and_no_contradiction(): void
    {
        $revenue = $this->record('m:revenue', 'Revenue', 'USD 12.4 billion', 'USD');
        self::assertTrue($this->grounded('Revenue reached 12.4 billion.', [$revenue]));
        self::assertFalse($this->grounded('12.4 billion were reached.', [$revenue]));
        foreach (['people', 'customers', 'employees', 'households', 'cases', 'tonnes'] as $noun) {
            self::assertFalse($this->grounded("Revenue reached 12.4 billion $noun.", [$revenue]), $noun);
        }
        $private = $this->record('m:private', 'Income from private sector', 'USD 10 million', 'USD');
        $public = $this->record('m:public', 'Income from public sector', 'USD 12 million', 'USD');
        self::assertFalse($this->grounded('Income reached 12 million.', [$private, $public]));
        $verdict = app(NarrativeVerifier::class)->verify(['narrative' => [[
            'claim' => 'Revenue reached 12.4 billion people.', 'cites' => ['m:revenue'],
        ], ['claim' => 'Revenue reached USD 12.4 billion.', 'cites' => ['m:revenue']]]],
            ['m:revenue'], ['m:revenue' => $revenue], []);
        self::assertSame('rejected', $verdict['status']);
        self::assertContains('numeric_fact_grounded', $verdict['reasons']);
    }

    public function test_same_magnitude_type_matrix(): void
    {
        $records = [
            'money' => $this->record('m:money', 'Funding', 'USD 10 million', 'USD'),
            'people' => $this->record('m:people', 'People reached', '10 million', 'people'),
            'litres' => $this->record('m:litres', 'Water delivered', '10 million', 'litres'),
            'tonnes' => $this->record('m:tonnes', 'Waste collected', '10 million', 'tonnes'),
            'percent' => $this->record('m:percent', 'Share', '10%', 'percent'),
        ];
        $claims = [
            'money' => 'Funding was USD 10 million.',
            'people' => '10 million people were reached.',
            'litres' => '10 million litres of water were delivered.',
            'tonnes' => '10 million tonnes of waste were collected.',
            'percent' => 'The share was 10%.',
        ];
        foreach ($records as $recordType => $record) {
            foreach ($claims as $claimType => $claim) {
                self::assertSame($recordType === $claimType, $this->grounded($claim, [$record]),
                    "$recordType -> $claimType");
            }
        }
    }

    public function test_composites_and_percentage_points_do_not_collapse(): void
    {
        $perPerson = $this->record('m:cost', 'Cost per person', '$20', 'USD per person');
        self::assertTrue($this->grounded('Cost was $20 per person.', [$perPerson]), 'USD per person');
        self::assertFalse($this->grounded('Cost was $20 total.', [$perPerson]));
        $perUnit = $this->record('m:unit', 'Cost per unit', '$20', 'USD per unit');
        self::assertTrue($this->grounded('Cost was $20 per unit.', [$perUnit]));
        self::assertFalse($this->grounded('Cost was $20 total.', [$perUnit]));
        $perYear = $this->record('m:tonnes', 'Waste collected per year', '10', 'tonnes per year');
        // Current typing calls this a duration; it cannot be safely admitted as a rate.
        self::assertFalse($this->grounded('Waste collected was 10 tonnes per year.', [$perYear]));
        self::assertFalse($this->grounded('Waste collected was 10 tonnes.', [$perYear]));
        $share = $this->record('m:share', 'Share', '5%', 'percent');
        self::assertFalse($this->grounded('Share increased by 5 percentage points.', [$share]));
        $points = $this->record('m:points', 'Share change', '5', 'percentage points');
        self::assertFalse($this->grounded('Share increased by 5%.', [$points]));
        $basis = $this->record('m:basis', 'Rate change', '5', 'basis points');
        self::assertFalse($this->grounded('Rate increased by 5 basis points.', [$basis]));
        $ratio = $this->record('m:ratio', 'Liquidity ratio', '2', 'ratio');
        self::assertTrue($this->grounded('Liquidity ratio was 2.', [$ratio]));
        self::assertFalse($this->grounded('Liquidity ratio was 2 people.', [$ratio]));
        $perHousehold = $this->record('m:household', 'People per household', '10', 'people per household');
        self::assertTrue($this->grounded('There were 10 people per household.', [$perHousehold]));
        self::assertFalse($this->grounded('There were 10 people.', [$perHousehold]));
    }

    public function test_qualifier_sign_and_scale_are_bound(): void
    {
        $over = $this->record('m:reach', 'People reached', 'more than 10 million', 'people');
        self::assertTrue($this->grounded('More than 10 million people were reached.', [$over]));
        self::assertFalse($this->grounded('10 million people were reached.', [$over]));
        $about = $this->record('m:reach', 'People reached', 'about 10 million', 'people');
        self::assertFalse($this->grounded('10 million people were reached.', [$about]));
        $loss = $this->record('m:loss', 'Loss', 'USD -10 million', 'USD');
        self::assertFalse($this->grounded('Loss was USD 10 million.', [$loss]));
        $profit = $this->record('m:profit', 'Profit', 'USD 10 million', 'USD');
        self::assertFalse($this->grounded('Loss was USD 10 million.', [$profit]));
        $money = $this->record('m:revenue', 'Revenue', 'USD 12.4 billion', 'USD');
        self::assertFalse($this->grounded('Revenue was USD 12.4 million.', [$money]));
        self::assertFalse($this->grounded('Revenue was USD 12.4 thousand.', [$money]));
        self::assertFalse($this->grounded('Revenue was USD 12.4.', [$money]));
        $range = $this->record('m:reach', 'People reached', '10–12 million', 'people');
        self::assertFalse($this->grounded('10 million people were reached.', [$range]));
        $under = $this->record('m:reach', 'People reached', 'less than 10 million', 'people');
        self::assertFalse($this->grounded('10 million people were reached.', [$under]));
        self::assertFalse($this->grounded('At most 10 million people were reached.', [$under]));
        self::assertFalse($this->grounded('At least 10 million people were reached.', [$over]));
        $plus = $this->record('m:change', 'Increase', '+10%', 'percent');
        self::assertFalse($this->grounded('The decrease was -10%.', [$plus]));
        $from = $this->record('m:from', 'Share', '40%', 'percent');
        $to = $this->record('m:to', 'Share', '46%', 'percent');
        self::assertFalse($this->grounded('Share increased by 6%.', [$from, $to]));
    }

    public function test_numeric_period_and_entity_ownership_remain_local(): void
    {
        $old = $this->record('m:old', 'Revenue', 'USD 10 million', 'USD', 'UNICEF', '2023');
        $new = $this->record('m:new', 'Revenue', 'USD 12 million', 'USD', 'UNICEF', '2024');
        self::assertTrue($this->grounded('Revenue was USD 10 million in 2023 and USD 12 million in 2024.',
            [$old, $new]));
        self::assertFalse($this->grounded('Revenue was USD 12 million in 2023 and USD 10 million in 2024.',
            [$old, $new]));
        $unicef = $this->record('m:unicef', 'Revenue', 'USD 10 million', 'USD', 'UNICEF');
        $other = $this->record('m:other', 'Revenue', 'USD 12 million', 'USD', 'Other Organization');
        self::assertFalse($this->grounded('UNICEF revenue was USD 12 million.', [$unicef, $other]));
    }

    public function test_unit_aliases_are_narrow_and_record_owned(): void
    {
        $usd = $this->record('m:revenue', 'Revenue', '$12.4 billion', 'USD');
        foreach (['USD 12.4 billion', 'US dollars 12.4 billion', '$12.4 billion'] as $amount) {
            self::assertTrue($this->grounded("Revenue was $amount.", [$usd]), $amount);
        }
        $people = $this->record('m:reach', 'People reached', '8 million', 'people');
        self::assertTrue($this->grounded('8 million people were reached.', [$people]));
        self::assertTrue($this->grounded('8 million person were reached.', [$people]));
        $percent = $this->record('m:share', 'Share', '5%', 'percent');
        self::assertTrue($this->grounded('Share was 5 percent.', [$percent]));
        self::assertTrue($this->grounded('Share was 5%.', [$percent]));
    }
}
