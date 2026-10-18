<?php

namespace Database\Seeders;

use App\Models\AiPrompt;
use Illuminate\Database\Seeder;

class DocumentTypePromptSeederV2 extends Seeder
{
    public function run(): void
    {
        if (AiPrompt::where('name', 'document_type')->where('version', 2)->exists()) {
            return;
        }
        if (! AiPrompt::where('name', 'document_type')->where('version', 1)->exists()) {
            $this->call(DocumentTypePromptSeeder::class);
        }
        $base = AiPrompt::where('name', 'document_type')->where('version', 1)->sole();
        $rule = <<<'PROMPT'
Classify the document's form and purpose from its substantive content. A board or management performance report prepared for a meeting is not meeting_minutes. Reserve meeting_minutes for an actual record of proceedings such as attendees, agenda, motions, resolutions, and recorded decisions. A report with financial and operational results, targets, risks, and proposed actions can be financial_report when financial performance is central. Monetary figures alone do not make payroll, contracts, or research financial reports. Use other if the controlled vocabulary has no suitable type. Do not rely on the filename alone.

PROMPT;
        AiPrompt::create(['name' => 'document_type', 'version' => 2,
            'provider' => $base->provider, 'model' => $base->model,
            'temperature' => $base->temperature, 'system_prompt' => $base->system_prompt,
            'template' => str_replace('Classify the document into exactly ONE', $rule.'Classify the document into exactly ONE', $base->template),
            'active' => false]);
    }
}
