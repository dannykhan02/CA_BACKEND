<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\DocumentChunk;
use App\Models\DocumentEvidence;
use App\Models\DocumentKpi;
use App\Services\AnthropicClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsIntelligenceFixtures;
use Tests\TestCase;

/**
 * The production-impact diagnostic: that it answers with the application's own semantics, and that
 * it cannot write, dispatch or pay for anything while doing so.
 */
class IntelligenceMoneyGroundingDiagnosticTest extends TestCase
{
    use BuildsIntelligenceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['intelligence_v2.enabled' => true, 'intelligence_v2.brief.enabled' => true,
            'intelligence_v2.b2.enabled' => false]);
        // Constructing a provider client at all is a failure, let alone calling one.
        $this->app->bind(AnthropicClient::class,
            fn () => throw new \LogicException('the diagnostic constructed a provider client'));
    }

    /** A document whose money is written the way the evaluation documents write it. */
    private function symbolMoney(): Document
    {
        $document = $this->intelligenceDocument('Symbol money.pdf');
        $this->metricFinding($document, 'Government investment in water', 'over $120 billion',
            'USD', '10 years', ['subject' => 'Government of India',
                'quantity_kind' => 'monetary', 'metric_type' => 'actual',
                'quote' => 'an investment of over $120 billion in water and sanitation over the past 10 years']);

        return $document->fresh();
    }

    /** @return array<string,mixed> */
    private function diagnose(array $options = []): array
    {
        $exit = Artisan::call('docintel:diagnose-money-grounding', $options + ['--json' => true]);
        self::assertSame(0, $exit);

        // The JSON is the command's last output block; anything a driver warns about comes first.
        $output = Artisan::output();
        $json = substr($output, (int) strpos($output, '{'));

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_it_counts_the_records_this_defect_was_excluding(): void
    {
        $document = $this->symbolMoney();
        $report = $this->diagnose(['--per-document' => true]);

        self::assertSame('none', $report['writes']);
        // RefreshDatabase already holds a transaction, so the command correctly reports that it
        // could not open its own read-only one. The read-only assertion below is what matters.
        self::assertFalse($report['read_only_transaction']);
        $totals = $report['totals'];
        self::assertSame(1, $totals['documents_inspected']);
        self::assertSame(1, $totals['monetary_records']);
        // Groundable now, and not groundable under the old ISO-in-`currency` rule.
        self::assertSame(1, $totals['monetary_records_eligible_under_fixed_code']);
        self::assertSame(1, $totals['monetary_records_excluded_by_currency_defect']);
        self::assertSame(1, $totals['documents_affected_by_currency_defect']);
        // The ISO code is attributed from the record's own unit, not guessed.
        self::assertSame(['USD' => 1], $report['by_currency']);

        self::assertSame([['document_id' => $document->id,
            'workspace_id' => $document->workspace_id, 'monetary_records' => 1,
            'excluded_by_currency_defect' => 1, 'figure_bearing_blocks' => 1]],
            $report['documents']);
    }

    /** A record that always carried its ISO code is not attributed to this defect. */
    public function test_a_record_that_already_stated_its_iso_code_is_not_counted(): void
    {
        $document = $this->intelligenceDocument('Iso money.pdf');
        $this->metricFinding($document, 'Total financing', '12.4', 'USD billion', 'FY2025',
            ['subject' => 'Acme', 'quantity_kind' => 'monetary',
                'quote' => 'Total financing: 12.4 USD billion (FY2025)']);

        $totals = $this->diagnose()['totals'];
        self::assertSame(1, $totals['monetary_records']);
        self::assertSame(1, $totals['monetary_records_eligible_under_fixed_code']);
        self::assertSame(0, $totals['monetary_records_excluded_by_currency_defect']);
        self::assertSame(0, $totals['documents_affected_by_currency_defect']);
    }

    /** A bare symbol with no code anywhere in the record stays ineligible, and is reported as such. */
    public function test_a_record_with_no_resolvable_code_stays_ineligible(): void
    {
        $document = $this->intelligenceDocument('Unknowable money.pdf');
        $this->metricFinding($document, 'Grant', '$97.4 million', null, null,
            ['subject' => 'Acme', 'quantity_kind' => 'monetary',
                'quote' => 'Grant: $97.4 million']);

        $totals = $this->diagnose()['totals'];
        self::assertSame(1, $totals['monetary_records']);
        self::assertSame(0, $totals['monetary_records_eligible_under_fixed_code']);
        self::assertSame(1, $totals['monetary_records_ineligible_for_other_reasons']);
        self::assertSame(0, $totals['monetary_records_excluded_by_currency_defect']);
    }

    /** Nothing is written, queued, charged or synthesized by a diagnostic run. */
    public function test_the_diagnostic_is_read_only(): void
    {
        $document = $this->symbolMoney();
        Bus::fake();

        $before = ['documents' => Document::count(), 'evidence' => DocumentEvidence::count(),
            'kpis' => DocumentKpi::count(), 'chunks' => DocumentChunk::count(),
            'runs' => DocumentAiRun::count(),
            'updated_at' => (string) $document->fresh()->updated_at];

        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|truncate|alter|drop|create)\b/i',
                $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $this->diagnose(['--per-document' => true]);

        self::assertSame([], $writes, 'the diagnostic issued a write statement');
        self::assertSame($before, ['documents' => Document::count(),
            'evidence' => DocumentEvidence::count(), 'kpis' => DocumentKpi::count(),
            'chunks' => DocumentChunk::count(), 'runs' => DocumentAiRun::count(),
            'updated_at' => (string) $document->fresh()->updated_at]);
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    /** `--limit` and `--workspace` really do bound the scan. */
    public function test_scope_options_bound_the_scan(): void
    {
        $this->symbolMoney();
        $second = $this->symbolMoney();

        self::assertSame(2, $this->diagnose()['totals']['documents_inspected']);
        self::assertSame(1, $this->diagnose(['--limit' => 1])['totals']['documents_inspected']);
        self::assertSame(1, $this->diagnose(['--workspace' => $second->workspace_id])
            ['totals']['documents_inspected']);
    }
}
