<?php

use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Models\DocumentIntelligenceRevision;
use App\Models\DocumentIntelligenceSummary;
use App\Models\DocumentTypeClassification;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Support\Facades\Storage;

// Run only with a disposable local database and local documents disk:
// PHASE2_BROWSER_PDF=/tmp/docintel-phase2-browser.pdf DB_PORT=55433
// DB_DATABASE=docintel_demo DOCUMENTS_DISK_DRIVER=local php artisan tinker
// --execute='require "tests/Fixtures/phase2_browser.php";'
if (! app()->environment('local') || config('database.connections.pgsql.database') !== 'docintel_demo'
    || config('filesystems.disks.documents.driver') !== 'local') {
    throw new RuntimeException('Phase 2 fixture requires the disposable local database and local documents disk.');
}

$pdfPath = getenv('PHASE2_BROWSER_PDF') ?: '/tmp/docintel-phase2-browser.pdf';
$bytes = file_get_contents($pdfPath);
$text = shell_exec('pdftotext '.escapeshellarg($pdfPath).' -');
if ($bytes === false || ! is_string($text) || ! str_contains($text, 'Revenue 20 USD in 2025.')) {
    throw new RuntimeException('Generated fixture PDF is missing or unreadable.');
}

$user = User::firstOrCreate(['email' => 'phase2-browser@example.test'], [
    'full_name' => 'Phase 2 Browser Fixture', 'password' => 'Password123!',
    'role' => 'Administrator', 'active' => true, 'email_verified_at' => now(),
]);
if (! $user->current_workspace_id) {
    app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
    $user->refresh();
}
$user->forceFill(['email_verified_at' => now()])->save();
$path = $user->current_workspace_id.'/phase2-browser.pdf';
Storage::disk('documents')->put($path, $bytes);
$document = Document::firstOrCreate(['workspace_id' => $user->current_workspace_id, 'file_path' => $path], [
    'name' => 'Phase 2 financial and contract fixture.pdf', 'type' => 'PDF',
    'status' => 'Ready', 'classification' => 'Internal', 'size_kb' => (int) ceil(strlen($bytes) / 1024),
    'year' => 2025, 'uploaded_by' => $user->id, 'last_updated_by' => $user->id,
    'pages' => 3, 'has_structured_data' => true, 'progress' => 100,
    'file_hash' => hash('sha256', $bytes), 'extracted_text' => $text,
]);
$pipeline = ['route' => 'incremental', 'key' => 'phase2-browser-fixture'];
$document->forceFill(['ai_pipeline' => $pipeline, 'intelligence_generation' => 1])->save();
$revision = DocumentIntelligenceRevision::firstOrCreate([
    'document_id' => $document->id, 'generation' => 1,
], [
    'workspace_id' => $document->workspace_id, 'state' => 'published',
    'file_hash' => $document->file_hash, 'extracted_text' => $text,
    'extracted_text_hash' => hash('sha256', $text), 'pipeline_key' => $pipeline['key'],
    'ai_pipeline' => $pipeline, 'pages' => 3, 'insights' => [],
    'has_structured_data' => true, 'published_at' => now(),
]);
$document->forceFill(['published_intelligence_revision_id' => $revision->id])->save();

DocumentTypeClassification::firstOrCreate([
    'document_id' => $document->id, 'intelligence_revision_id' => $revision->id,
], [
    'workspace_id' => $document->workspace_id, 'document_type' => 'financial_report',
    'confidence' => 1, 'reasoning' => 'Synthetic financial observations in the fixture',
    'prompt_version' => 'fixture',
]);
$facts = [
    ['id' => 'fact:phase2revenue2024', 'quote' => 'Revenue 10 USD in 2024.', 'value' => '10', 'period' => '2024', 'page' => 1],
    ['id' => 'fact:phase2revenue2025', 'quote' => 'Revenue 20 USD in 2025.', 'value' => '20', 'period' => '2025', 'page' => 2],
];
foreach ($facts as $fact) {
    $start = mb_strpos($text, $fact['quote']);
    DocumentEvidence::firstOrCreate(['document_id' => $document->id, 'source_id' => $fact['id']], [
        'workspace_id' => $document->workspace_id, 'pipeline_key' => $pipeline['key'],
        'identity' => hash('sha256', $fact['id']), 'kind' => 'metric',
        'data' => ['label' => 'Revenue', 'value' => $fact['value'], 'unit' => 'USD',
            'period' => $fact['period'], 'subject' => 'Group', 'metric_type' => 'actual',
            'confidence' => 1, 'quote' => $fact['quote']],
        'sources' => [['quote' => $fact['quote'], 'start_offset' => $start,
            'end_offset' => $start + mb_strlen($fact['quote']), 'page' => $fact['page']]],
    ]);
}
DocumentIntelligenceSummary::firstOrCreate([
    'document_id' => $document->id, 'intelligence_revision_id' => $revision->id,
], [
    'workspace_id' => $document->workspace_id,
    'executive_summary' => 'Revenue 10 USD in 2024 [fact:phase2revenue2024]. Revenue 20 USD in 2025 [fact:phase2revenue2025]. [FY2025] is a document label.',
    'key_findings' => [], 'critical_risks' => [], 'upcoming_deadlines' => [],
    'important_entities' => [], 'recommended_attention' => [], 'prompt_version' => 'fixture',
]);
echo json_encode(['document_id' => $document->id, 'fixture_email' => $user->email], JSON_THROW_ON_ERROR);
