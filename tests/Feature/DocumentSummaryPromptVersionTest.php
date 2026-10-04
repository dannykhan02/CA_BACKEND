<?php

namespace Tests\Feature;

use App\Models\AiPrompt;
use Database\Seeders\DocumentSummaryPromptSeeder;
use Database\Seeders\DocumentSummaryPromptSeederV2;
use Database\Seeders\DocumentSummaryPromptSeederV3;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentSummaryPromptVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_prompt_seeders_are_repeatable_and_keep_bounded_version_active(): void
    {
        $this->seed(DocumentSummaryPromptSeeder::class);
        $this->seed(DocumentSummaryPromptSeederV2::class);
        $this->seed(DocumentSummaryPromptSeeder::class);
        $this->seed(DocumentSummaryPromptSeederV2::class);
        $this->assertSame(2, AiPrompt::active('document_summary')->version);
        $this->assertSame(2, AiPrompt::where('name', 'document_summary')->count());
        $template = AiPrompt::active('document_summary')->template;
        $this->assertStringContainsString('"executive_assessment":{"text":"string"', $template);
        $this->assertStringContainsString('Use null for executive_assessment', $template);
        $this->assertStringContainsString('up to 350 characters', $template);
    }

    public function test_v3_prompt_is_repeatable_and_makes_the_assessment_contract_explicit(): void
    {
        $this->seed(DocumentSummaryPromptSeederV3::class);
        $this->seed(DocumentSummaryPromptSeederV3::class);

        $prompt = AiPrompt::active('document_summary');
        $this->assertSame(3, $prompt->version);
        $this->assertSame(3, AiPrompt::where('name', 'document_summary')->count());
        $this->assertStringContainsString('executive_assessment must be either null or an object', $prompt->template);
        $this->assertStringContainsString('executive_assessment.text', $prompt->template);
        $this->assertStringContainsString('1-350 characters', $prompt->template);
        $this->assertStringNotContainsString('}|null', $prompt->template);
    }
}
