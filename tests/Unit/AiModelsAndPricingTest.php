<?php

namespace Tests\Unit;

use App\Services\AI\AiModels;
use App\Services\AI\AiPricing;
use Tests\TestCase;

class AiModelsAndPricingTest extends TestCase
{
    public function test_central_task_routing_and_prices_are_model_specific(): void
    {
        config(['services.anthropic.extraction_model' => 'claude-haiku-4-5-20251001',
            'services.anthropic.synthesis_model' => 'claude-sonnet-5-5']);
        $models = app(AiModels::class);
        foreach (['extraction', 'document_type', 'entities', 'risks', 'deadlines', 'context_resolution', 'ocr', 'chart_vision', 'kpi_identity', 'repair'] as $task) {
            self::assertSame('claude-haiku-4-5-20251001', $models->forTask($task));
        }
        foreach (['document_summary', 'synthesis', 'summary_repair', 'document_comparison', 'document_qa'] as $task) {
            self::assertSame('claude-sonnet-5-5', $models->forTask($task));
        }
        $usage = ['input_tokens' => 1000000, 'output_tokens' => 1000000];
        self::assertSame(6.0, app(AiPricing::class)->estimate($models->forTask('extraction'), $usage));
        self::assertSame(12.0, app(AiPricing::class)->estimate($models->forTask('document_summary'), $usage));
        self::assertSame(2.7, app(AiPricing::class)->estimate('claude-sonnet-5-5',
            ['cache_creation_input_tokens' => 1000000, 'cache_read_input_tokens' => 1000000]));
    }

    public function test_model_override_uses_its_own_price_and_unknown_models_fail_closed(): void
    {
        config(['services.anthropic.synthesis_model' => 'claude-sonnet-4-6']);
        self::assertSame(18.0, app(AiPricing::class)->reserve(app(AiModels::class)->forTask('document_summary'), 1000000, 1000000));
        self::assertNull(app(AiPricing::class)->reserve('unpriced-model', 1000, 1000));
    }

    public function test_empty_synthesis_override_does_not_fall_back_to_haiku(): void
    {
        config(['services.anthropic.synthesis_model' => null, 'services.anthropic.model' => 'claude-haiku-4-5-20251001']);
        self::assertSame('claude-sonnet-5-5', app(AiModels::class)->forTask('document_summary'));
    }
}
