<?php

namespace Tests\Unit;

use App\Services\AnthropicClient;
use App\Services\Intelligence\NegativeClaimGuard;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntelligenceNegativeClaimScreenTest extends TestCase
{
    public function test_approved_positive_pattern_table_has_multiple_sentences_in_each_group(): void
    {
        $sentences = [
            'existence_negation' => [
                'No material risks were identified in the report.',
                'None were reported by the issuer.',
                'The matter was not addressed in the filing.',
                'The document does not disclose penalties.',
                'The report is without any significant risk.',
                'There are no material exceptions.',
                'The filing lacks detail.',
                'An absence of disclosure remains.',
                'Nothing to report this quarter.',
                'The issuer fails to disclose the terms.',
                'They never mentioned the covenant.',
            ],
            'clean_bill' => [
                'All obligations are met.',
                'No outstanding items remain.',
                'The company is fully compliant.',
                'The insurer described comprehensive coverage.',
            ],
        ];
        $guard = new NegativeClaimGuard;
        foreach ($sentences as $group => $items) {
            self::assertGreaterThanOrEqual(3, count($items), $group);
            foreach ($items as $sentence) {
                self::assertTrue($guard->matchesProse($sentence), $sentence);
                self::assertTrue($guard->matchesProse(mb_strtoupper($sentence)), $sentence.' uppercase');
            }
        }
        self::assertSame('1', config('intelligence_v2.negative_claim.version'));
    }

    public function test_benign_sentences_and_embedded_word_fragments_do_not_match(): void
    {
        $guard = new NegativeClaimGuard;
        foreach ([
            'Known risks were identified in the report.',
            'Nobody identified the issue.',
            'The report does disclose the schedule.',
            'The report lists outstanding items.',
            'The analysis covers compliance fully.',
            'The committee found ten risks.',
            'The absence rate was five percent.',
            'Issues were addressed by management.',
            'The noon deadline remains open.',
        ] as $sentence) {
            self::assertFalse($guard->matchesProse($sentence), $sentence);
        }
    }

    private function block(): array
    {
        return ['type' => 'finding', 'origin' => 'docintel_ai',
            'text' => 'No material risks were identified.', 'detail' => null,
            'cites' => ['fact:1']];
    }

    private function request(): array
    {
        return ['predicate' => 'risk_severity_in(high,critical)',
            'scope' => 'pipeline:current', 'template_id' => 'absence.high_critical_risks'];
    }

    public function test_match_and_passing_guard_emits_only_a_declared_deterministic_absence_template(): void
    {
        app()->bind(AnthropicClient::class, fn () => throw new \LogicException('Provider reached'));
        Http::fake();
        $result = (new NegativeClaimGuard)->screenAiBlock($this->block(), ['state' => 'complete'], [
            ['kind' => 'risk', 'data' => ['severity' => 'low'], 'provenance' => ['origin' => 'document']],
        ], $this->request());
        self::assertTrue($result['rejected']);
        self::assertSame('negative_claim', $result['reason']);
        self::assertFalse($result['omitted']);
        self::assertSame('docintel_deterministic', $result['block']['origin']);
        self::assertSame('absent', $result['block']['assertion']);
        self::assertSame('No high or critical risks were identified.', $result['block']['text']);
        self::assertNotSame($this->block()['text'], $result['block']['text']);
        self::assertSame(['predicate' => 'risk_severity_in(high,critical)', 'scope' => 'pipeline:current',
            'matched' => 0], $result['block']['absence_check']);
        Http::assertNothingSent();
    }

    public function test_match_without_a_declared_predicate_never_creates_a_template(): void
    {
        app()->bind(AnthropicClient::class, fn () => throw new \LogicException('Provider reached'));
        Http::fake();
        $result = (new NegativeClaimGuard)->screenAiBlock($this->block(), ['state' => 'complete'], []);
        self::assertTrue($result['rejected']);
        self::assertTrue($result['omitted']);
        self::assertNull($result['block']);
        Http::assertNothingSent();
    }

    public function test_match_and_failing_guards_emit_no_absence_or_template_substitution(): void
    {
        app()->bind(AnthropicClient::class, fn () => throw new \LogicException('Provider reached'));
        Http::fake();
        $guard = new NegativeClaimGuard;
        foreach (['partial', 'bounded', 'unavailable'] as $state) {
            $result = $guard->screenAiBlock($this->block(), ['state' => $state], [], $this->request());
            self::assertNull($result['block'], $state);
            self::assertSame('negative_claim', $result['reason']);
        }
        $unknown = $guard->screenAiBlock($this->block(), ['state' => 'complete'], [
            ['kind' => 'fact', 'provenance' => ['origin' => 'unknown']],
        ], $this->request());
        self::assertNull($unknown['block']);
        $matched = $guard->screenAiBlock($this->block(), ['state' => 'complete'], [
            ['kind' => 'risk', 'data' => ['severity' => 'critical'], 'provenance' => ['origin' => 'document']],
        ], $this->request());
        self::assertNull($matched['block']);
        Http::assertNothingSent();
    }

    public function test_non_absence_fallback_requires_the_same_cited_records(): void
    {
        $guard = new NegativeClaimGuard;
        $fallback = fn ($block) => ['origin' => 'docintel_deterministic', 'assertion' => 'derived',
            'text' => 'One cited finding is available.', 'template_id' => 'finding.cited',
            'cites' => $block['cites']];
        $result = $guard->screenAiBlock($this->block(), ['state' => 'bounded'], [], null, $fallback);
        self::assertSame('derived', $result['block']['assertion']);
        self::assertSame(['fact:1'], $result['block']['cites']);
        $wrong = $guard->screenAiBlock($this->block(), ['state' => 'bounded'], [], null,
            fn ($block) => [...$fallback($block), 'cites' => ['fact:2']]);
        self::assertNull($wrong['block']);
    }
}
