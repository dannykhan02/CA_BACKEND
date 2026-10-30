<?php

use App\Models\AiPrompt;
use Database\Seeders\DocumentComparisonPromptSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Keep the old prompt row for historical provenance. Preserve custom active templates.
        $active = AiPrompt::where('name', 'document_comparison')->where('active', true)->first();
        (new DocumentComparisonPromptSeeder)->run();
        if ($active && (int) $active->version === 1
            && hash('sha256', $active->template) === '4fbd2a86f753d79001c46681bb7f02f3479782ee26cac1fee57363c92e8ab080') {
            $active->update(['active' => false]);
            AiPrompt::where('name', 'document_comparison')->where('version', 2)->update(['active' => true]);
        }
    }

    public function down(): void
    {
        // Historical prompt rows may be referenced by saved comparisons; never delete them.
    }
};
