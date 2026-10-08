<?php

namespace Tests\Unit;

use App\Models\DocumentEvidence;
use App\Services\AnthropicClient;
use App\Services\Embeddings\VoyageEmbeddingClient;
use App\Services\Intelligence\ProvenanceProjector;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntelligenceNumericEquivalentGroundingTest extends TestCase
{
    private function origin(string $value, string $quote, ?string $unit = null,
        ?string $period = null, string $label = 'Funding'): string
    {
        $row = new DocumentEvidence;
        $row->data = ['kind' => 'metric', 'label' => $label, 'value' => $value,
            'quote' => $quote, 'unit' => $unit, 'period' => $period, 'due_date' => null];

        return app(ProvenanceProjector::class)->project($row)['origin'];
    }

    public function test_safe_numeric_notation_equivalents_are_document_origin(): void
    {
        foreach ([
            ['6%', 'The rate was 6 %.', null],
            ['99%', 'Coverage reached 99 per cent.', null],
            ['1,200', 'The count was 1200.', null],
            ['$1.9M', 'Funding reached $1.9 million.', null],
            ['USD 1.9 million', 'Funding reached USD 1.9M.', null],
        ] as [$value, $quote, $unit]) {
            self::assertSame('document', $this->origin($value, $quote, $unit), $value.' / '.$quote);
        }
    }

    public function test_different_measurement_semantics_remain_unknown(): void
    {
        foreach ([
            ['20%', 'The rate was 20.', null, null, 'Rate'],
            ['20', 'The rate was 20%.', null, null, 'Rate'],
            ['$1.9 million', 'Funding reached $1.9 billion.', null, null, 'Funding'],
            ['USD 1.9 million', 'Funding reached EUR 1.9 million.', null, null, 'Funding'],
            ['ABC 1.9M', 'Funding reached ABC 1.9 million.', null, null, 'Funding'],
            ['10', 'The count was over 10.', null, null, 'Count'],
            ['over 10', 'The count was 10.', null, null, 'Count'],
            ['USD 16.1 million', 'Belgian Committee | 16.1', null, null, 'Funding'],
            ['28 %', '28% spent on humanitarian action.', null, null,
                'Core Resources as percentage of total spending'],
            ['over 20,000 cases through digital management system',
                'reaching over 20,000 cases through a digital management system', null, null, 'Cases'],
            ['6%', 'The rate was 6 % in 2024.', null, '2025', 'Rate'],
        ] as [$value, $quote, $unit, $period, $label]) {
            self::assertSame('unknown', $this->origin($value, $quote, $unit, $period, $label),
                $value.' / '.$quote);
        }
    }

    public function test_numeric_exception_makes_no_query_or_provider_call(): void
    {
        app()->bind(AnthropicClient::class, fn () => throw new \LogicException('Provider reached'));
        app()->bind(VoyageEmbeddingClient::class, fn () => throw new \LogicException('Provider reached'));
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        self::assertSame('document', $this->origin('6%', 'The rate was 6 %.'));
        self::assertSame([], $queries);
    }
}
