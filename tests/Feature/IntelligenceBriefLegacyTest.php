<?php

namespace Tests\Feature;

use App\Models\DocumentDeadline;
use App\Services\AnthropicClient;
use App\Services\Intelligence\Brief\BriefAssembler;
use App\Services\Intelligence\Brief\LegacyBriefAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsIntelligenceFixtures;
use Tests\TestCase;

class IntelligenceBriefLegacyTest extends TestCase
{
    use BuildsIntelligenceFixtures, RefreshDatabase;

    public function test_normal_route_builds_bounded_deterministic_brief_from_scoped_rows(): void
    {
        app()->bind(AnthropicClient::class, fn () => throw new \LogicException('B1 reached a provider client'));
        $document = $this->intelligenceDocument(incremental: false);
        $other = $this->intelligenceDocument(incremental: false);
        $deadline = DocumentDeadline::create([
            'document_id' => $document->id, 'workspace_id' => $document->workspace_id,
            'deadline_type' => 'obligation', 'title' => 'Filing',
            'description' => 'Filing due 8 October 2026', 'due_date' => '2026-10-08',
            'date_type' => 'explicit', 'evidence' => 'Filing due 8 October 2026',
            'confidence' => 0.9, 'status' => 'open', 'prompt_version' => '3',
        ]);
        DocumentDeadline::create([
            'document_id' => $document->id, 'workspace_id' => $other->workspace_id,
            'deadline_type' => 'obligation', 'title' => 'Other workspace filing',
            'description' => 'Other workspace filing due 9 October 2026',
            'due_date' => '2026-10-09', 'date_type' => 'explicit',
            'evidence' => 'Other workspace filing due 9 October 2026',
            'confidence' => 0.9, 'status' => 'open', 'prompt_version' => '3',
        ]);
        $records = app(LegacyBriefAdapter::class)->records($document);
        self::assertSame(['deadline:'.$deadline->id], array_column($records, 'source_id'));
        self::assertSame('document', $records[0]['provenance']['origin']);
        $brief = app(BriefAssembler::class)->assembleLegacy($document,
            new \DateTimeImmutable('2026-10-07T00:00:00+00:00'));
        self::assertFalse($brief['ai_blocks_available']);
        self::assertContains('timeline.calendar_due', array_column($brief['blocks'], 'template_id'));
        self::assertSame('coverage_note.legacy_route', end($brief['blocks'])['template_id']);
        foreach ($brief['blocks'] as $block) {
            self::assertSame([], $block['evidence']);
            self::assertFalse($block['ai_generated']);
        }
    }
}
