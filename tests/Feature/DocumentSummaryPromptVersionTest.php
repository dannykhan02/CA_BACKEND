<?php

namespace Tests\Feature;

use App\Models\AiPrompt;
use Database\Seeders\DocumentSummaryPromptSeeder;
use Database\Seeders\DocumentSummaryPromptSeederV2;
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
    }
}
