<?php

namespace Tests\Unit;

use App\Models\Document;
use App\Services\Intelligence\DocumentTypeAdjudicator;
use PHPUnit\Framework\TestCase;

class DocumentTypeAdjudicatorTest extends TestCase
{
    public function test_quarterly_board_performance_content_is_not_meeting_minutes(): void
    {
        $document = new Document;
        $document->extracted_text = "Q3 2026 Board Performance Review\nFinancial performance: Revenue KES 51 million, operating costs KES 41 million and profit KES 10 million.\nOperations: delivery 87% against target 95%; complaints 133 against target 90.\nRisks and proposed recovery actions are submitted for board decision.";
        $result = (new DocumentTypeAdjudicator)->adjudicate($document,
            ['document_type' => 'meeting_minutes', 'confidence' => 0.92, 'reasoning' => 'Board review.']);
        $this->assertSame('financial_report', $result['document_type']);
        $this->assertLessThanOrEqual(0.85, $result['confidence']);
    }

    public function test_actual_meeting_record_remains_minutes_even_when_it_mentions_finance(): void
    {
        $document = new Document;
        $document->extracted_text = "Minutes of the meeting, 18 October 2026\nMembers present: chair and directors.\nAgenda item 2: quarterly revenue, profit and operating costs were discussed.\nAgenda item 3: delivery and complaints were discussed.\nMotion carried: approve the next meeting date.";
        $result = ['document_type' => 'meeting_minutes', 'confidence' => 0.9,
            'reasoning' => 'Records the proceedings.'];
        $this->assertSame($result, (new DocumentTypeAdjudicator)->adjudicate($document, $result));
    }

    public function test_a_document_with_money_alone_is_not_reclassified(): void
    {
        $document = new Document;
        $document->extracted_text = 'Payroll register Q3 2026: salaries and tax deductions in KES.';
        $result = ['document_type' => 'meeting_minutes', 'confidence' => 0.6, 'reasoning' => 'Uncertain.'];
        $this->assertSame($result, (new DocumentTypeAdjudicator)->adjudicate($document, $result));
    }
}
