<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentDeadline;
use App\Models\DocumentEvidence;
use App\Models\DocumentKpi;
use App\Models\KpiDefinition;
use App\Models\User;
use App\Services\AnthropicClient;
use App\Services\Intelligence\DocumentAnalysisComposer;
use App\Services\Intelligence\ImportantFindingsBuilder;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsIntelligenceFixtures;
use Tests\TestCase;

/**
 * The derived intelligence layer, exercised against stored evidence in the shape the pipeline
 * actually persists. No provider call is possible here: TestCase blocks stray HTTP, and nothing
 * in this path issues a request.
 */
class DocumentIntelligenceAnalysisTest extends TestCase
{
    use BuildsIntelligenceFixtures, RefreshDatabase;

    private function analyze(Document $document): array
    {
        return app(DocumentAnalysisComposer::class)->compose($document->fresh());
    }

    /** A rich report: a trend, a sector breakdown, a regional comparison and supporting findings. */
    private function annualReport(): Document
    {
        $document = $this->intelligenceDocument();
        foreach ([['2022', '9.8'], ['2023', '10.7'], ['2024', '12.4']] as [$period, $value]) {
            $this->metricFinding($document, 'Total financing', $value, 'USD billion', $period);
        }
        // The same metric written at another scale and under a second label the document also uses.
        $this->metricFinding($document, 'Total financing approved', '11,500', 'USD million', '2021');
        foreach ([['Infrastructure', '31'], ['Energy', '24'], ['Agriculture', '18'], ['Other', '27']] as [$sector, $share]) {
            $this->metricFinding($document, $sector.' share of financing', $share, '%', '2024');
        }
        foreach ([['East Africa', '5.1'], ['West Africa', '3.4'], ['Southern Africa', '2.2'], ['North Africa', '1.7']] as [$region, $value]) {
            $this->metricFinding($document, 'Portfolio exposure', $value, 'USD billion', '2024', ['subject' => $region]);
        }
        $this->metricFinding($document, 'Employees', '1,240', 'employees', '2023');
        $this->metricFinding($document, 'Employees', '1,310', 'employees', '2024');

        $this->riskFinding($document, 'Concentration risk in two markets', 'high');
        $this->riskFinding($document, 'Currency volatility exposure', 'medium');

        $kpi = $document->kpis()->first();
        $this->synthesis($document, [
            'material_findings' => [[
                'title' => 'Financing growth is concentrated in infrastructure',
                'category' => 'financial', 'explanation' => 'Infrastructure absorbed the largest share of new financing.',
                'why_it_matters' => 'Sector concentration raises correlated exposure across the book.',
                'severity' => 'high', 'basis' => 'explicit', 'source_ids' => ['kpi:'.$kpi->id],
            ]],
            'trends' => [[
                'observation' => 'Approvals have risen in each of the last three reporting years',
                'significance' => 'Sustained growth increases funding requirements.',
                'basis' => 'explicit', 'source_ids' => ['kpi:'.$kpi->id],
            ]],
        ]);

        foreach ([['Covenant review', '2026-03-31'], ['Facility maturity', '2027-06-30']] as [$title, $due]) {
            $deadline = DocumentDeadline::create(['document_id' => $document->id, 'workspace_id' => $document->workspace_id,
                'deadline_type' => 'obligation', 'title' => $title, 'description' => $title.' falls due.',
                'due_date' => $due, 'date_type' => 'explicit', 'confidence' => 0.9, 'status' => 'open',
                'evidence' => $title.' is due on '.$due, 'prompt_version' => '3', 'provider' => 'anthropic']);
            $this->evidenceRow($document, 'deadline', ['label' => $title, 'value' => $title.' falls due.',
                'date_type' => 'explicit', 'due_date' => $due], 'deadline:'.$deadline->id);
        }

        foreach (range(1, 40) as $index) {
            $this->evidenceRow($document, 'fact', ['label' => 'Operating note '.$index,
                'value' => 'The group reported operating detail number '.$index.'.', 'confidence' => 0.6], null);
        }
        foreach ([['organization', 'African Development Bank'], ['person', 'Chief Financial Officer'],
            ['location', 'Nairobi'], ['regulator', 'Capital Markets Authority']] as [$type, $value]) {
            $this->evidenceRow($document, 'entity', ['label' => $value, 'value' => $value, 'entity_type' => $type], null);
        }

        return $document;
    }

    // ---------------------------------------------------------------- charts from evidence

    public function test_non_kpi_metrics_produce_a_chronological_time_series(): void
    {
        $document = $this->intelligenceDocument();
        foreach ([['2024', '12.4'], ['2022', '9.8'], ['2023', '10.7']] as [$period, $value]) {
            $this->metricFinding($document, 'Total financing', $value, 'USD billion', $period);
        }
        $this->assertNull($document->kpis()->first()->kpi_definition_id, 'fixture must not rely on KPI identity');

        $charts = $this->analyze($document)['visualAnalysis']['charts'];
        $this->assertCount(1, $charts);
        $this->assertSame('time_series', $charts[0]['basis']);
        $this->assertSame('line', $charts[0]['type']);
        $this->assertSame('Total financing', $charts[0]['title']);
        $this->assertSame('USD billion', $charts[0]['unit']);
        $this->assertSame(['2022', '2023', '2024'], array_column($charts[0]['points'], 'label'));
        $this->assertSame([9.8, 10.7, 12.4], array_column($charts[0]['points'], 'value'));
    }

    public function test_v2_chart_takeaway_uses_one_formatter_and_never_reaches_a_provider_client(): void
    {
        config(['intelligence_v2.enabled' => true]);
        app()->bind(AnthropicClient::class, fn () => throw new \LogicException('Stage A reached a provider client'));
        $document = $this->intelligenceDocument();
        $this->metricFinding($document, 'Total financing', '100', 'USD billion', '2023');
        $this->metricFinding($document, 'Total financing', '110', 'USD billion', '2024');

        $takeaways = $this->analyze($document)['overview']['takeaways'];
        $metric = collect($takeaways)->firstWhere('origin', 'metric');
        self::assertNotNull($metric);
        self::assertStringContainsString('USD 100 billion', $metric['text']);
        self::assertStringNotContainsString('USD billion USD', $metric['text']);
    }

    public function test_a_canonical_kpi_identity_groups_differently_worded_labels(): void
    {
        $document = $this->intelligenceDocument();
        $first = $this->metricFinding($document, 'Total financing', '9.8', 'USD billion', '2023');
        $second = $this->metricFinding($document, 'Financing approvals', '12.4', 'USD billion', '2024');
        $definition = KpiDefinition::create(['id' => (string) Str::uuid(),
            'workspace_id' => $document->workspace_id, 'canonical_name' => 'Total financing',
            'normalized_name' => 'total financing', 'matching_metadata' => [], 'identity_key' => hash('sha256', 'total financing')]);
        DocumentKpi::whereIn('id', [$first->id, $second->id])->update(['kpi_definition_id' => $definition->id]);

        $charts = $this->analyze($document)['visualAnalysis']['charts'];
        $this->assertCount(1, $charts);
        $this->assertSame(['2023', '2024'], array_column($charts[0]['points'], 'label'));
    }

    public function test_scale_differences_in_one_currency_are_normalized_into_one_series(): void
    {
        $document = $this->intelligenceDocument();
        $this->metricFinding($document, 'Total financing', '9,800', 'USD million', '2023');
        $this->metricFinding($document, 'Total financing', '12.4', 'USD billion', '2024');

        $charts = $this->analyze($document)['visualAnalysis']['charts'];
        $this->assertCount(1, $charts);
        $this->assertSame('USD billion', $charts[0]['unit']);
        $this->assertSame([9.8, 12.4], array_column($charts[0]['points'], 'value'));
    }

    public function test_a_currency_mismatch_is_never_charted_as_one_series(): void
    {
        $document = $this->intelligenceDocument();
        $this->metricFinding($document, 'Total financing', '9.8', 'USD billion', '2023');
        $this->metricFinding($document, 'Total financing', '1,420', 'KES billion', '2024');

        $analysis = $this->analyze($document);
        $this->assertSame([], $analysis['visualAnalysis']['charts']);
        $this->assertSame(2, $analysis['visualAnalysis']['rejected']['insufficient_points']);
    }

    public function test_incompatible_measurements_are_never_combined(): void
    {
        $document = $this->intelligenceDocument();
        $this->metricFinding($document, 'Revenue', '4.2', 'USD billion', '2024');
        $this->metricFinding($document, 'Revenue', '63', '%', '2024');
        $this->metricFinding($document, 'Headcount', '1,240', 'employees', '2024');

        $this->assertSame([], $this->analyze($document)['visualAnalysis']['charts']);
    }

    public function test_quarters_are_not_plotted_against_years(): void
    {
        $document = $this->intelligenceDocument();
        $this->metricFinding($document, 'Total financing', '9.8', 'USD billion', '2023');
        $this->metricFinding($document, 'Total financing', '2.1', 'USD billion', 'Q1 2024');
        $this->metricFinding($document, 'Total financing', '2.4', 'USD billion', 'Q2 2024');

        $charts = $this->analyze($document)['visualAnalysis']['charts'];
        $this->assertCount(1, $charts);
        $this->assertSame(['Q1 2024', 'Q2 2024'], array_column($charts[0]['points'], 'label'));
    }

    public function test_a_fiscal_year_is_not_plotted_against_the_calendar_year(): void
    {
        $document = $this->intelligenceDocument();
        $this->metricFinding($document, 'Total financing', '9.8', 'USD billion', '2023');
        $this->metricFinding($document, 'Total financing', '12.4', 'USD billion', 'FY2024');

        $this->assertSame([], $this->analyze($document)['visualAnalysis']['charts']);
    }

    public function test_subjects_measured_in_one_period_form_a_categorical_chart(): void
    {
        $document = $this->intelligenceDocument();
        foreach ([['East Africa', '5.1'], ['West Africa', '3.4'], ['Southern Africa', '2.2']] as [$region, $value]) {
            $this->metricFinding($document, 'Portfolio exposure', $value, 'USD billion', '2024', ['subject' => $region]);
        }

        $charts = $this->analyze($document)['visualAnalysis']['charts'];
        $this->assertCount(1, $charts);
        $this->assertSame('categorical', $charts[0]['basis']);
        $this->assertSame('bar', $charts[0]['type']);
        $this->assertSame(['East Africa', 'West Africa', 'Southern Africa'], array_column($charts[0]['points'], 'label'));
    }

    public function test_percentage_shares_that_sum_to_a_hundred_form_a_composition(): void
    {
        $document = $this->intelligenceDocument();
        foreach ([['Infrastructure', '31'], ['Energy', '24'], ['Agriculture', '18'], ['Other', '27']] as [$sector, $share]) {
            $this->metricFinding($document, $sector.' share of financing', $share, '%', '2024');
        }

        $charts = $this->analyze($document)['visualAnalysis']['charts'];
        $this->assertCount(1, $charts);
        $this->assertSame('composition', $charts[0]['basis']);
        $this->assertSame('pie', $charts[0]['type']);
        $this->assertSame(['Infrastructure', 'Other', 'Energy', 'Agriculture'], array_column($charts[0]['points'], 'label'));
        $this->assertSame('%', $charts[0]['unit']);
    }

    public function test_percentages_that_do_not_partition_a_whole_are_not_a_pie_chart(): void
    {
        $document = $this->intelligenceDocument();
        foreach ([['Infrastructure', '31'], ['Energy', '24'], ['Agriculture', '18']] as [$sector, $share]) {
            $this->metricFinding($document, $sector.' share of financing', $share, '%', '2024');
        }

        $analysis = $this->analyze($document);
        $this->assertSame([], array_filter($analysis['visualAnalysis']['charts'], fn ($chart) => $chart['type'] === 'pie'));
        $this->assertArrayHasKey('not_a_composition', $analysis['visualAnalysis']['rejected']);
    }

    public function test_parts_that_add_up_to_a_stated_total_form_a_composition(): void
    {
        $document = $this->intelligenceDocument();
        foreach ([['Infrastructure', '5.0'], ['Energy', '4.0'], ['Agriculture', '3.4']] as [$sector, $value]) {
            $this->metricFinding($document, 'Financing by sector', $value, 'USD billion', '2024', ['subject' => $sector]);
        }
        $this->metricFinding($document, 'Financing by sector', '12.4', 'USD billion', '2024', ['subject' => 'Total']);

        $charts = $this->analyze($document)['visualAnalysis']['charts'];
        $this->assertCount(1, $charts);
        $this->assertSame('composition', $charts[0]['basis']);
        $this->assertSame(['Infrastructure', 'Energy', 'Agriculture'], array_column($charts[0]['points'], 'label'));
    }

    public function test_a_single_data_point_is_never_a_chart(): void
    {
        $document = $this->intelligenceDocument();
        $this->metricFinding($document, 'Total financing', '12.4', 'USD billion', '2024');

        $analysis = $this->analyze($document);
        $this->assertSame([], $analysis['visualAnalysis']['charts']);
        $this->assertSame(1, $analysis['stats']['chartableFindings']);
    }

    public function test_malformed_numeric_evidence_is_rejected_rather_than_guessed(): void
    {
        $document = $this->intelligenceDocument();
        foreach (['not disclosed', 'USD 12.4bn (2023: 10.7bn)', '', 'page 42'] as $index => $value) {
            $this->metricFinding($document, 'Total financing', $value, $index === 3 ? 'pages' : 'USD billion', '202'.$index);
        }

        $analysis = $this->analyze($document);
        $this->assertSame(4, $analysis['stats']['metricFindings']);
        $this->assertSame(0, $analysis['stats']['chartableFindings']);
        $this->assertSame([], $analysis['visualAnalysis']['charts']);
    }

    public function test_conflicting_readings_for_one_period_drop_that_period(): void
    {
        $document = $this->intelligenceDocument();
        $this->metricFinding($document, 'Total financing', '9.8', 'USD billion', '2023');
        $this->metricFinding($document, 'Total financing', '12.4', 'USD billion', '2024');
        $this->metricFinding($document, 'Total financing', '13.9', 'USD billion', '2024');

        $charts = $this->analyze($document)['visualAnalysis']['charts'];
        $this->assertSame([], $charts);
    }

    public function test_a_duplicate_observation_of_one_period_is_not_two_points(): void
    {
        $document = $this->intelligenceDocument();
        $this->metricFinding($document, 'Total financing', '9.8', 'USD billion', '2023');
        $this->metricFinding($document, 'Total financing approved', '9.8', 'USD billion', '2023');
        $this->metricFinding($document, 'Total financing', '12.4', 'USD billion', '2024');

        $charts = $this->analyze($document)['visualAnalysis']['charts'];
        $this->assertCount(1, $charts);
        $this->assertSame(['2023', '2024'], array_column($charts[0]['points'], 'label'));
    }

    // ---------------------------------------------------------------- takeaways

    public function test_takeaways_come_from_synthesis_and_evidence_and_keep_their_references(): void
    {
        $analysis = $this->analyze($this->annualReport());
        $takeaways = $analysis['overview']['takeaways'];

        $this->assertGreaterThanOrEqual(3, count($takeaways));
        $this->assertLessThanOrEqual(8, count($takeaways));
        $origins = array_unique(array_column($takeaways, 'origin'));
        $this->assertContains('synthesis', $origins);
        $this->assertContains('metric', $origins);
        foreach ($takeaways as $takeaway) {
            $this->assertNotSame([], $takeaway['sourceIds'], $takeaway['text']);
            $this->assertGreaterThan(24, mb_strlen($takeaway['text']));
        }
        $texts = implode("\n", array_column($takeaways, 'text'));
        $this->assertStringContainsString('Total financing increased from 9.8 USD billion in 2022 to 12.4 USD billion in 2024', $texts);
        $this->assertStringContainsString('dated obligations extend beyond 2024', $texts);
    }

    public function test_takeaways_do_not_repeat_the_same_point(): void
    {
        $document = $this->intelligenceDocument();
        $this->synthesis($document, ['material_findings' => array_fill(0, 4, [
            'title' => 'Financing grew strongly across the reporting period',
            'category' => 'financial', 'explanation' => 'Approvals rose.', 'why_it_matters' => 'Funding needs rise.',
            'severity' => 'medium', 'basis' => 'explicit', 'source_ids' => ['fact:1'],
        ])]);

        $this->assertCount(1, $this->analyze($document)['overview']['takeaways']);
    }

    public function test_a_takeaway_references_only_evidence_from_its_own_document(): void
    {
        $document = $this->annualReport();
        $own = $document->kpis()->pluck('id')->map(fn ($id) => 'kpi:'.$id)
            ->merge($document->risks()->pluck('id')->map(fn ($id) => 'risk:'.$id))
            ->merge($document->deadlines()->pluck('id')->map(fn ($id) => 'deadline:'.$id))
            ->merge($document->evidence_source_ids ?? [])->all();

        foreach ($this->analyze($document)['overview']['takeaways'] as $takeaway) {
            foreach ($takeaway['sourceIds'] as $reference) {
                $this->assertContains($reference, $own, $reference.' is not this document\'s evidence');
            }
        }
    }

    public function test_an_ungrounded_summary_finding_is_never_presented_as_a_takeaway(): void
    {
        $document = $this->intelligenceDocument();
        $this->synthesis($document, ['key_findings' => [
            'Total financing grew across the reporting period and is expected to continue.',
            'The portfolio remains concentrated in a small number of regional markets.',
        ]]);

        $analysis = $this->analyze($document);
        $this->assertSame([], $analysis['overview']['takeaways'], 'nothing grounded exists, so there are no takeaways');
        // The only overview this document has is still offered, but as unsupported summary text.
        $notes = $analysis['overview']['summaryNotes'];
        $this->assertCount(2, $notes);
        foreach ($notes as $note) {
            $this->assertFalse($note['supported']);
            $this->assertArrayNotHasKey('sourceIds', $note);
        }
        $this->assertSame(2, $analysis['stats']['summaryNotes']);
    }

    public function test_every_takeaway_a_rich_document_produces_is_grounded(): void
    {
        $document = $this->annualReport();
        $document->intelligenceSummary->update(['key_findings' => [
            'An unsupported sentence the synthesis wrote without citing anything at all.',
        ]]);

        $analysis = $this->analyze($document);
        $this->assertGreaterThanOrEqual(3, count($analysis['overview']['takeaways']));
        foreach ($analysis['overview']['takeaways'] as $takeaway) {
            $this->assertNotSame([], $takeaway['sourceIds'], $takeaway['text']);
        }
        $texts = array_column($analysis['overview']['takeaways'], 'text');
        $this->assertNotContains('An unsupported sentence the synthesis wrote without citing anything at all.', $texts);
        // Grounded takeaways are plentiful here, so the unsupported text is not offered at all.
        $this->assertSame([], $analysis['overview']['summaryNotes']);
    }

    public function test_unsupported_notes_never_repeat_a_grounded_takeaway(): void
    {
        $document = $this->intelligenceDocument();
        $this->riskFinding($document, 'Concentration risk in two markets', 'high');
        $this->synthesis($document, ['key_findings' => [
            'Concentration risk in two markets was identified.',
            'Separately, the group reported a material change in its funding mix this year.',
        ]]);

        $analysis = $this->analyze($document);
        $this->assertCount(1, $analysis['overview']['takeaways']);
        $this->assertSame(['Separately, the group reported a material change in its funding mix this year.'],
            array_column($analysis['overview']['summaryNotes'], 'text'));
    }

    // ---------------------------------------------------------------- groups, findings, stats

    public function test_analysis_groups_are_derived_from_the_findings_present(): void
    {
        $groups = $this->analyze($this->annualReport())['analysisGroups'];
        $keys = array_column($groups, 'key');

        $this->assertContains('financial', $keys);
        $this->assertContains('context', $keys);
        $this->assertContains('geography', $keys);
        $this->assertNotContains('definitions', $keys, 'a group with no findings must not be emitted');
        foreach ($groups as $group) {
            $this->assertNotSame([], $group['items']);
            $this->assertLessThanOrEqual(6, count($group['items']));
            $this->assertGreaterThanOrEqual(count($group['items']), $group['total']);
        }
        // Risks, obligations and deadlines keep their own sections instead of being duplicated here.
        $this->assertSame([], array_intersect($keys, ['risks', 'deadlines', 'obligations']));
    }

    public function test_important_findings_are_traceable_and_do_not_repeat_the_charts(): void
    {
        $analysis = $this->analyze($this->annualReport());
        $charted = array_merge(...array_column($analysis['visualAnalysis']['charts'], 'sourceIds'));
        $stated = array_merge(...array_column($analysis['overview']['takeaways'], 'sourceIds'));

        $this->assertNotSame([], $analysis['importantFindings']);
        foreach ($analysis['importantFindings'] as $finding) {
            $this->assertNotSame('', $finding['sourceId']);
            $this->assertNotContains($finding['sourceId'], $charted);
            $this->assertNotContains($finding['sourceId'], $stated);
            $this->assertNotSame('unresolved', $finding['kind']);
        }
    }

    public function test_usefulness_decides_the_order_rather_than_extraction_confidence(): void
    {
        // The ranking table is exercised directly: at composer level the most consequential risks
        // and obligations are promoted into takeaways first, so they never reach this section.
        $document = $this->intelligenceDocument();
        $rows = [
            'Line item 1' => ['metric', ['value' => '101', 'unit' => 'USD thousand', 'confidence' => 0.99]],
            'Supplier concentration' => ['risk', ['value' => 'Two suppliers dominate.', 'severity' => 'medium', 'confidence' => 0.5]],
            'Covenant breach exposure' => ['risk', ['value' => 'A breach is possible.', 'severity' => 'critical', 'confidence' => 0.4]],
            'Control weakness' => ['risk', ['value' => 'A control is weak.', 'severity' => 'high', 'confidence' => 0.45]],
            'Facility refinancing' => ['obligation', ['value' => 'The facility must be refinanced.',
                'date_type' => 'explicit', 'due_date' => now()->addYear()->toDateString(), 'confidence' => 0.3]],
            'Office relocation' => ['fact', ['value' => 'The head office moved.', 'confidence' => 0.97]],
        ];
        foreach ($rows as $label => [$kind, $data]) {
            $this->evidenceRow($document, $kind, ['label' => $label] + $data, null);
        }

        $findings = app(ImportantFindingsBuilder::class)->build(
            DocumentEvidence::where('document_id', $document->id)->orderBy('identity')->get(), null, []);
        $labels = array_column($findings, 'label');

        $this->assertSame([
            'Covenant breach exposure',  // critical risk
            'Control weakness',          // high risk
            'Facility refinancing',      // an obligation still ahead, on the lowest confidence here
            'Supplier concentration',    // medium risk
            'Line item 1',               // a metric, however certain
            'Office relocation',
        ], $labels);
        $this->assertSame('critical', $findings[0]['severity']);
        $this->assertSame(now()->addYear()->toDateString(), $findings[2]['dueDate']);
    }

    public function test_an_obligation_that_has_already_passed_ranks_below_one_still_ahead(): void
    {
        $document = $this->intelligenceDocument();
        foreach ([['Historic filing', '-2 years'], ['Upcoming filing', '+2 years']] as [$title, $offset]) {
            $this->evidenceRow($document, 'obligation', ['label' => $title, 'value' => $title.' is required.',
                'date_type' => 'explicit', 'due_date' => now()->modify($offset)->toDateString()], null);
        }
        $this->evidenceRow($document, 'obligation', ['label' => 'Undated covenant',
            'value' => 'Reporting is required within 30 days.', 'date_type' => 'relative', 'due_date' => null], null);

        $labels = array_column(app(ImportantFindingsBuilder::class)->build(
            DocumentEvidence::where('document_id', $document->id)->orderBy('identity')->get(), null, []), 'label');

        $this->assertSame(['Upcoming filing', 'Historic filing', 'Undated covenant'], $labels);
    }

    public function test_a_synthesis_citation_promotes_a_finding_by_one_tier_only(): void
    {
        $document = $this->intelligenceDocument();
        $this->riskFinding($document, 'Supplier concentration', 'medium');
        $fact = $this->evidenceRow($document, 'fact', ['label' => 'Funding mix',
            'value' => 'Wholesale funding rose to a third of the balance sheet.', 'confidence' => 0.5], 'fact:cited');
        $this->evidenceRow($document, 'fact', ['label' => 'Office relocation',
            'value' => 'The head office moved during the year.', 'confidence' => 0.95], null);
        // A tension is cited evidence that never becomes a takeaway, so the promotion is visible.
        $this->synthesis($document, ['tensions' => [[
            'observation' => 'Funding mix shifted towards wholesale sources',
            'significance' => 'Wholesale funding is more expensive and less stable.',
            'basis' => 'explicit', 'source_ids' => [$fact->source_id],
        ]]]);

        $findings = $this->analyze($document)['importantFindings'];
        $labels = array_column($findings, 'label');

        // The cited fact overtakes the more confident uncited one, but not the risk above its tier.
        $this->assertSame(['Supplier concentration', 'Funding mix', 'Office relocation'], $labels);
        $this->assertTrue($findings[1]['citedBySynthesis']);
        $this->assertFalse($findings[2]['citedBySynthesis']);
    }

    public function test_no_single_kind_or_table_may_fill_the_section(): void
    {
        $document = $this->intelligenceDocument();
        foreach (['Supplier concentration', 'Currency volatility', 'Staff attrition', 'Vendor lock in'] as $title) {
            $this->riskFinding($document, $title, 'medium');
        }
        foreach (['Processing fee', 'Settlement amount', 'Advisory charge', 'Custody charge'] as $index => $label) {
            $this->metricFinding($document, $label, (string) (100 + $index), 'USD thousand', '2024');
        }
        foreach (['Office relocation', 'Policy refresh', 'Committee renewal', 'Vendor review'] as $label) {
            $this->evidenceRow($document, 'fact', ['label' => $label, 'value' => $label.' took place during the year.'], null);
        }
        // Twelve rows of one table: eligible, but never more than two of them.
        foreach (range(1, 12) as $index) {
            $this->metricFinding($document, 'Line item '.$index, (string) (200 + $index), 'USD thousand', '2023');
        }

        $findings = $this->analyze($document)['importantFindings'];
        $counts = array_count_values(array_column($findings, 'kind'));
        $stems = array_count_values(array_map(fn ($finding) => preg_replace('/\d+/', '', $finding['label']), $findings));

        $this->assertCount(8, $findings);
        foreach ($counts as $kind => $count) {
            $this->assertLessThanOrEqual(3, $count, $kind);
        }
        foreach ($stems as $stem => $count) {
            $this->assertLessThanOrEqual(2, $count, $stem);
        }
        $this->assertGreaterThanOrEqual(3, count($counts), 'the section must show more than one kind of finding');
    }

    public function test_a_rich_report_reports_its_own_derivation_counts(): void
    {
        $stats = $this->analyze($this->annualReport())['stats'];

        $this->assertSame(62, $stats['acceptedFindings']);
        $this->assertSame(14, $stats['metricFindings']);
        $this->assertSame(14, $stats['chartableFindings']);
        $this->assertGreaterThanOrEqual(4, $stats['chartCandidates']);
        $this->assertGreaterThanOrEqual(3, $stats['takeaways']);
        $this->assertGreaterThanOrEqual(4, $stats['analysisGroups']);
        $this->assertSame(62, $stats['groundedSources']);
    }

    // ---------------------------------------------------------------- the other document shapes

    public function test_a_small_document_with_few_metrics_degrades_quietly(): void
    {
        $document = $this->intelligenceDocument('Letter.pdf');
        $this->metricFinding($document, 'Settlement amount', '45,000', 'USD', '2024');
        $this->evidenceRow($document, 'fact', ['label' => 'Parties', 'value' => 'Two parties signed the letter.'], null);

        $analysis = $this->analyze($document);
        $this->assertSame([], $analysis['visualAnalysis']['charts']);
        $this->assertSame([], $analysis['overview']['takeaways']);
        $this->assertNotSame([], $analysis['importantFindings']);
        $this->assertSame(['context', 'financial'], array_column($analysis['analysisGroups'], 'key'));
    }

    public function test_a_document_whose_metrics_share_no_comparable_dimension_shows_no_charts(): void
    {
        $document = $this->intelligenceDocument('Policy.pdf');
        foreach ([['Processing fee', '250', 'USD'], ['Review window', '30', 'days'],
            ['Approval threshold', '75', '%'], ['Committee size', '7', 'members']] as [$label, $value, $unit]) {
            $this->metricFinding($document, $label, $value, $unit, '2024');
        }

        $analysis = $this->analyze($document);
        $this->assertSame(4, $analysis['stats']['chartableFindings']);
        $this->assertSame([], $analysis['visualAnalysis']['charts']);
        $this->assertNotSame([], $analysis['analysisGroups']);
    }

    public function test_an_older_document_without_evidence_rows_still_produces_charts(): void
    {
        $document = $this->intelligenceDocument('Legacy report.pdf', incremental: false);
        foreach ([['2022', '9.8'], ['2023', '10.7'], ['2024', '12.4']] as [$period, $value]) {
            DocumentKpi::create(['document_id' => $document->id, 'workspace_id' => $document->workspace_id,
                'label' => 'Total financing', 'period' => $period, 'value' => $value, 'unit' => 'USD billion',
                'value_numeric' => (float) $value]);
        }
        $this->assertSame(0, DocumentEvidence::where('document_id', $document->id)->count());

        $analysis = $this->analyze($document);
        $this->assertCount(1, $analysis['visualAnalysis']['charts']);
        $this->assertSame([9.8, 10.7, 12.4], array_column($analysis['visualAnalysis']['charts'][0]['points'], 'value'));
        // No evidence rows exist, so the evidence-backed sections are empty rather than invented.
        $this->assertSame([], $analysis['analysisGroups']);
        $this->assertSame([], $analysis['importantFindings']);
        $this->assertSame(0, $analysis['stats']['acceptedFindings']);
    }

    public function test_a_legacy_kpi_whose_raw_value_is_unreadable_uses_its_stored_numeric_form(): void
    {
        $document = $this->intelligenceDocument('Legacy report.pdf', incremental: false);
        foreach ([['2023', '64.2M', 64200000.0], ['2024', '70.1M', 70100000.0]] as [$period, $value, $numeric]) {
            DocumentKpi::create(['document_id' => $document->id, 'workspace_id' => $document->workspace_id,
                'label' => 'Subscribers', 'period' => $period, 'value' => $value, 'unit' => null, 'value_numeric' => $numeric]);
        }

        $charts = $this->analyze($document)['visualAnalysis']['charts'];
        $this->assertCount(1, $charts);
        $this->assertSame([64.2, 70.1], array_column($charts[0]['points'], 'value'));
        $this->assertSame('million', $charts[0]['unit']);
    }

    // ---------------------------------------------------------------- api contract and isolation

    public function test_the_intelligence_endpoint_ships_the_derived_analysis(): void
    {
        $document = $this->annualReport();
        Sanctum::actingAs(User::find($document->uploaded_by));

        $response = $this->getJson("/api/documents/{$document->id}/intelligence")->assertOk();
        $response->assertJsonStructure(['data' => ['analysis' => [
            'overview' => ['takeaways'], 'visualAnalysis' => ['charts', 'omitted', 'rejected'],
            'analysisGroups', 'importantFindings', 'stats',
        ]]]);
        $this->assertNotSame([], $response->json('data.analysis.visualAnalysis.charts'));
    }

    public function test_a_processing_document_is_not_analyzed_on_every_poll(): void
    {
        $document = $this->intelligenceDocument('Report.pdf', status: 'Processing');
        Sanctum::actingAs(User::find($document->uploaded_by));

        $this->getJson("/api/documents/{$document->id}/intelligence")->assertOk()
            ->assertJsonPath('data.analysis', null);
    }

    public function test_another_workspace_cannot_reach_the_derived_analysis(): void
    {
        $document = $this->annualReport();
        $intruder = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($intruder);
        Sanctum::actingAs($intruder->fresh());

        $this->getJson("/api/documents/{$document->id}/intelligence")->assertForbidden();
    }

    public function test_evidence_of_another_document_is_never_collected_into_this_analysis(): void
    {
        $document = $this->annualReport();
        $other = $this->intelligenceDocument('Other report.pdf');
        foreach ([['2022', '1.1'], ['2023', '2.2'], ['2024', '3.3']] as [$period, $value]) {
            $this->metricFinding($other, 'Unrelated metric', $value, 'USD billion', $period);
        }

        $analysis = $this->analyze($document);
        $titles = array_column($analysis['visualAnalysis']['charts'], 'title');
        $this->assertNotContains('Unrelated metric', $titles);
        $foreign = $other->kpis()->pluck('id')->map(fn ($id) => 'kpi:'.$id)->all();
        $charted = array_merge(...array_column($analysis['visualAnalysis']['charts'], 'sourceIds'));
        $this->assertSame([], array_intersect($charted, $foreign));
    }

    public function test_the_response_sends_source_excerpts_only_for_what_it_references(): void
    {
        $document = $this->annualReport();
        // The long tail a real annual report leaves behind: row-level table figures that are
        // individually real, reachable through the paginated endpoints, and referenced by nothing
        // on the page.
        foreach (range(1, 20) as $index) {
            $this->metricFinding($document, 'Line item '.$index, (string) (100 + $index), 'USD thousand', '2024');
        }
        Sanctum::actingAs(User::find($document->uploaded_by));

        $response = $this->getJson("/api/documents/{$document->id}/intelligence")->assertOk();
        $evidence = $response->json('data.evidence');
        $stored = DocumentEvidence::where('document_id', $document->id)->whereNotNull('source_id')->count();

        $this->assertSame(38, $stored);
        $this->assertLessThan($stored, count($evidence), 'unreferenced evidence must not be shipped');

        // Every risk and deadline the response returns keeps its excerpt and span locations, and
        // nothing else is sent unless the derived analysis or the synthesis points at it.
        $rendered = [
            ...$document->risks->map(fn ($risk) => 'risk:'.$risk->id)->all(),
            ...$document->deadlines->map(fn ($deadline) => 'deadline:'.$deadline->id)->all(),
        ];
        foreach ($rendered as $reference) {
            $this->assertArrayHasKey($reference, $evidence, $reference);
        }
        $allowed = [...$rendered, ...app(DocumentAnalysisComposer::class)->referencedSourceIds($response->json('data.analysis'))];
        foreach (array_keys($evidence) as $reference) {
            $this->assertContains($reference, $allowed, $reference);
        }
    }

    public function test_a_rich_document_response_stays_within_a_sane_payload_size(): void
    {
        $document = $this->annualReport();
        Sanctum::actingAs(User::find($document->uploaded_by));

        $body = $this->getJson("/api/documents/{$document->id}/intelligence")->assertOk()->getContent();
        $this->assertLessThan(262144, strlen($body), 'intelligence payload must stay well under 256KB');
    }

    /** CR-010 calibration: new case; the protected V1 assertions above remain unchanged. */
    public function test_fixture_25_preserves_approved_v2_calibration(): void
    {
        app()->bind(AnthropicClient::class, fn () => throw new \LogicException('Stage A reached a provider client'));
        $document = $this->annualReport();
        $composer = app(DocumentAnalysisComposer::class);
        $asOf = new \DateTimeImmutable('2026-10-07T00:00:00+00:00');
        config(['intelligence_v2.enabled' => false]);
        $v1 = $composer->compose($document->fresh(), $asOf);
        config(['intelligence_v2.enabled' => true]);
        $v2 = $composer->compose($document->fresh(), $asOf);
        $expected = json_decode(file_get_contents(base_path('tests/Fixtures/intelligence-v2/expected/25-takeaways-v2.json')),
            true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($expected, array_map(fn ($item) => ['origin' => $item['origin'], 'text' => $item['text']],
            $v2['overview']['takeaways']));
        self::assertSame(['risk', 'metric'], array_column(array_slice($v1['importantFindings'], 0, 2), 'kind'));
        self::assertSame(array_slice(array_column($v1['importantFindings'], 'label'), 0, 2),
            array_slice(array_column($v2['importantFindings'], 'label'), 0, 2));
        // CR-009: the fact/definition cap boundary falls inside an exact-score group.
        self::assertSame(['Operating note 1', 'Operating note 10'],
            array_slice(array_column($v2['importantFindings'], 'label'), 2, 2));
        self::assertSame(['African Development Bank', 'Capital Markets Authority', 'Chief Financial Officer'],
            array_slice(array_column($v2['importantFindings'], 'label'), 4));
        $financingApproved = collect($v2['importantFindings'])->firstWhere('label', 'Total financing approved');
        self::assertSame(3, $financingApproved['materialityTier']);
        self::assertNull($financingApproved['forcedRule']);
        $tier1Ids = array_column($v2['tier1'], 'sourceId');
        self::assertNotContains($financingApproved['sourceId'], $tier1Ids);
        foreach ($document->risks->whereIn('severity', ['critical', 'high']) as $risk) {
            self::assertContains('risk:'.$risk->id, $tier1Ids);
        }
        foreach ($document->deadlines->where('due_date', '>=', $asOf->format('Y-m-d')) as $deadline) {
            self::assertContains('deadline:'.$deadline->id, $tier1Ids);
        }
        self::assertSame($v1['visualAnalysis']['charts'], $v2['visualAnalysis']['charts']);
    }
}
