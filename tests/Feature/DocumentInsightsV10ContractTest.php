<?php

namespace Tests\Feature;

use App\Models\AiPrompt;
use Database\Seeders\DocumentInsightsPromptSeederV10;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DocumentInsightsV10ContractTest extends TestCase
{
    use DatabaseTransactions;

    public function test_v10_seeds_inactive_without_changing_the_v9_prompt_or_requesting_charts(): void
    {
        $this->seed(DocumentInsightsPromptSeederV10::class);
        $v9 = AiPrompt::where('name', 'document_insights')->where('version', 9)->sole();
        $v10 = AiPrompt::where('name', 'document_insights')->where('version', 10)->sole();
        self::assertFalse($v10->active);
        self::assertNotSame($v9->template, $v10->template);
        self::assertStringContainsString('"charts":', $v9->template);
        self::assertStringNotContainsString('"charts":', $v10->template);
        self::assertStringNotContainsString('charts[].data', $v10->template);
        self::assertStringContainsString('one observation per relevant period', $v10->template);
        self::assertStringContainsString('genuine governing entity', $v10->template);
        self::assertStringContainsString('identity.period and period_span_id null', $v10->template);
        self::assertStringContainsString('"source_context":', $v10->template);
        self::assertStringContainsString('"identity":', $v10->template);
    }
}
