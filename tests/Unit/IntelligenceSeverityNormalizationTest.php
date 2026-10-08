<?php

namespace Tests\Unit;

use App\Services\AnthropicClient;
use App\Services\Intelligence\Attention\AttentionStateBuilder;
use App\Services\Intelligence\Attention\HistoricalRiskRule;
use App\Services\Intelligence\Materiality\MaterialityScorer;
use App\Services\Intelligence\Materiality\SignalEvaluator;
use App\Services\Intelligence\NegativeClaimGuard;
use App\Services\Intelligence\SeverityNormalizer;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntelligenceSeverityNormalizationTest extends TestCase
{
    public function test_mixed_case_and_padded_critical_severities_drive_every_v2_decision(): void
    {
        app()->bind(AnthropicClient::class, fn () => throw new \LogicException('Provider reached'));
        Http::fake();
        $asOf = new \DateTimeImmutable('2026-10-07');
        foreach (['Critical' => 'critical', ' HIGH ' => 'high'] as $raw => $severity) {
            $record = ['identity' => $severity, 'source_id' => 'risk:'.$severity, 'kind' => 'risk',
                'data' => ['label' => $severity.' risk', 'value' => $severity.' risk', 'severity' => $raw],
                'typed' => ['value' => null, 'dates' => []],
                'sources' => [['span_id' => 'E'.$severity, 'start_offset' => 0, 'end_offset' => 20,
                    'page' => 1, 'quote' => $severity.' risk']],
                'status' => 'open', 'provenance' => ['origin' => 'document', 'assertion' => 'stated',
                    'attribution' => ['role' => 'unattributed']]];
            $context = ['span_count' => null, 'cited_source_ids' => [], 'comparable_source_ids' => []];
            self::assertSame($severity, SeverityNormalizer::normalize($raw));
            $assignment = app(MaterialityScorer::class)->assign([$record], $context, $asOf)[$severity];
            self::assertSame($severity.'_risk', $assignment['kind_class']);
            self::assertSame($severity.'_risk', $assignment['forced_rule']);
            self::assertTrue($assignment['forced']);
            self::assertSame(config('intelligence_v2.materiality.signals.severity.'.$severity),
                app(SignalEvaluator::class)->values($record, [$record], $context, $asOf)['severity']['value']);
            $attention = (new AttentionStateBuilder(new HistoricalRiskRule, config('intelligence_v2.attention')))
                ->build($record, $assignment, [$record], $asOf);
            self::assertSame('needs_attention', $attention['state']);

            $screened = (new NegativeClaimGuard)->screenAiBlock([
                'type' => 'finding', 'origin' => 'docintel_ai', 'text' => 'No material risks were identified.',
            ], ['state' => 'complete'], [$record], ['template_id' => 'absence.high_critical_risks',
                'predicate' => 'risk_severity_in(high,critical)', 'scope' => 'pipeline:current']);
            self::assertNull($screened['block']);
        }
        self::assertNull(SeverityNormalizer::normalize('urgent'));
        Http::assertNothingSent();
    }
}
