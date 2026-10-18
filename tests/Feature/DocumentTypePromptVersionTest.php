<?php

namespace Tests\Feature;

use App\Models\AiPrompt;
use Database\Seeders\DocumentTypePromptSeederV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentTypePromptVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_v2_requires_content_based_meeting_classification_and_is_not_auto_activated(): void
    {
        (new DocumentTypePromptSeederV2)->run();
        $v2 = AiPrompt::where('name', 'document_type')->where('version', 2)->sole();
        $this->assertFalse($v2->active);
        $this->assertStringContainsString('record of proceedings', $v2->template);
        $this->assertStringContainsString('performance report prepared for a meeting', $v2->template);
    }
}
